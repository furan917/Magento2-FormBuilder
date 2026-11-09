<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\FieldGroup;

use Furan\FormBuilder\Controller\Adminhtml\FieldGroup\AbstractBuilderEndpoint;
use Furan\FormBuilder\Controller\Adminhtml\FieldGroup\Create;
use Furan\FormBuilder\Controller\Adminhtml\FieldGroup\Insert;
use Furan\FormBuilder\Controller\Adminhtml\FieldGroup\Palette;
use Furan\FormBuilder\Model\FieldGroup\BuilderGroups;
use Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form\ControllerTestCase;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator;

class BuilderEndpointsTest extends ControllerTestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private BuilderGroups $groups;
    private Json $result;
    private Validator $formKey;
    /** @var array<string, mixed> */
    private array $params = [];
    /** @var list<string> */
    private array $allowed = [];

    private function build(string $class): AbstractBuilderEndpoint
    {
        $this->request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(fn (string $resource) => in_array($resource, $this->allowed, true));
        $this->context->method('getAuthorization')->willReturn($auth);
        $this->groups = $this->double('groups', BuilderGroups::class);
        $this->result = $this->double('result', Json::class);
        $this->result->method('setHttpResponseCode')->willReturnSelf();
        $this->result->method('setData')->willReturnSelf();
        $factory = $this->createStub(JsonFactory::class);
        $factory->method('create')->willReturn($this->result);
        $this->formKey = $this->createStub(Validator::class);
        return new $class($this->context, $this->groups, $factory, $this->formKey);
    }

    private function isAllowed(AbstractBuilderEndpoint $controller): bool
    {
        return (new \ReflectionMethod($controller, '_isAllowed'))->invoke($controller);
    }

    public function testItUsesOneResourcePerActionAndOnlyPost(): void
    {
        $this->assertSame('Furan_FormBuilder::field_groups_edit', Create::ADMIN_RESOURCE);
        $this->assertSame('Furan_FormBuilder::field_groups', Palette::ADMIN_RESOURCE);
        $this->assertSame('Furan_FormBuilder::field_groups', Insert::ADMIN_RESOURCE);
        foreach ([Create::class, Palette::class, Insert::class] as $class) {
            $this->assertTrue(is_subclass_of($class, HttpPostActionInterface::class), $class);
        }
    }

    public function testItChecksTheResourceOfEachAction(): void
    {
        $this->allowed = ['Furan_FormBuilder::field_groups'];
        $this->assertFalse($this->isAllowed($this->build(Create::class)));
        $this->assertTrue($this->isAllowed($this->build(Palette::class)));
        $this->assertTrue($this->isAllowed($this->build(Insert::class)));
    }

    public function testItRequiresBothFieldGroupRightsToCreateAGroup(): void
    {
        foreach ([[], ['Furan_FormBuilder::field_groups'], ['Furan_FormBuilder::field_groups_edit']] as $allowed) {
            $this->allowed = $allowed;
            $this->assertFalse($this->isAllowed($this->build(Create::class)), implode(',', $allowed));
        }

        $this->allowed = ['Furan_FormBuilder::field_groups', 'Furan_FormBuilder::field_groups_edit'];
        $this->assertTrue($this->isAllowed($this->build(Create::class)));
    }

    public function testItChecksTheFormKeyExplicitly(): void
    {
        $controller = $this->build(Create::class);
        $this->formKey->method('validate')->willReturnOnConsecutiveCalls(true, false);

        $this->assertTrue($controller->validateForCsrf($this->request));
        $this->assertFalse($controller->validateForCsrf($this->request));
        $this->assertInstanceOf(InvalidRequestException::class, $controller->createCsrfValidationException($this->request));
    }

    public function testItSavesThePostedGroupAndAnswersWithTheServiceResult(): void
    {
        $this->asserted = ['groups', 'result'];
        $this->params = ['payload' => '{"name":"A"}'];
        $controller = $this->build(Create::class);
        $this->groups->expects($this->once())->method('create')->with('{"name":"A"}')->willReturn([200, ['success' => true, 'id' => 3]]);
        $this->result->expects($this->once())->method('setHttpResponseCode')->with(200)->willReturnSelf();
        $this->result->expects($this->once())->method('setData')->with(['success' => true, 'id' => 3])->willReturnSelf();

        $this->assertSame($this->result, $controller->execute());
    }

    public function testItListsAPageOfGroupsAfterTheGivenId(): void
    {
        $this->asserted = ['groups'];
        $this->params = ['after' => '12abc'];
        $controller = $this->build(Palette::class);
        $this->groups->expects($this->once())->method('palette')->with(12)->willReturn([200, ['success' => true]]);

        $controller->execute();
    }

    public function testItFetchesTheGroupToInsertByTheStoredId(): void
    {
        $this->asserted = ['groups', 'result'];
        $this->params = ['id' => '7'];
        $controller = $this->build(Insert::class);
        $this->groups->expects($this->once())->method('fetch')->with(7)->willReturn([404, ['success' => false]]);
        $this->result->expects($this->once())->method('setHttpResponseCode')->with(404)->willReturnSelf();

        $controller->execute();
    }
}
