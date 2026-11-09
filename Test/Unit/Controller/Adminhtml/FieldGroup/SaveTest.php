<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\FieldGroup;

use Furan\FormBuilder\Controller\Adminhtml\FieldGroup\Save;
use Furan\FormBuilder\Model\FieldGroup;
use Furan\FormBuilder\Model\FieldGroup\FieldGroupManager;
use Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form\ControllerTestCase;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class SaveTest extends ControllerTestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private FieldGroupManager $manager;
    private DataPersistorInterface $persistor;

    private function controller(array $params, bool $allowed = true): Save
    {
        $this->request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(static fn (string $resource) => $allowed || $resource !== 'Furan_FormBuilder::field_groups_edit');
        $this->context->method('getAuthorization')->willReturn($auth);
        $this->manager = $this->double('manager', FieldGroupManager::class);
        $this->persistor = $this->double('persistor', DataPersistorInterface::class);
        return new Save($this->context, $this->manager, $this->persistor, new \Magento\Framework\Serialize\Serializer\Json());
    }

    private function isAllowed(Save $controller): bool
    {
        return (new \ReflectionMethod($controller, '_isAllowed'))->invoke($controller);
    }

    public function testItRefusesEditsWithoutTheEditPermission(): void
    {
        $controller = $this->controller(['id' => '4', 'name' => 'x'], false);

        $this->assertSame('Furan_FormBuilder::field_groups_edit', Save::ADMIN_RESOURCE);
        $this->assertFalse($this->isAllowed($controller));
    }

    public function testItAllowsEditsWithTheEditPermission(): void
    {
        $this->assertTrue($this->isAllowed($this->controller(['id' => '4'])));
    }

    public function testItSavesTheNameDescriptionFieldsAndTranslationsOfAnExistingGroup(): void
    {
        $this->asserted = ['manager'];
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/')->willReturnSelf();
        $controller = $this->controller([
            'id' => '4', 'name' => 'Renamed', 'description' => 'Text', 'group_id' => '9',
            'fields' => '[{"id":"f_a","kind":"field"}]', 'translations' => '{"1":{"items":{}}}',
        ]);
        $group = $this->createStub(FieldGroup::class);
        $group->method('getData')->willReturn('Renamed');
        $this->manager->expects($this->once())->method('save')->with([
            'name' => 'Renamed', 'description' => 'Text',
            'fields' => [['id' => 'f_a', 'kind' => 'field']], 'translations' => ['1' => ['items' => []]],
        ], 4)->willReturn($group);

        $this->assertSame($this->redirect, $controller->execute());
    }

    public function testItKeepsTheStoredFieldsWhenNoneArePosted(): void
    {
        $this->asserted = ['manager'];
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/edit', ['id' => 4])->willReturnSelf();
        $controller = $this->controller(['id' => '4', 'name' => 'Renamed', 'back' => 'edit']);
        $group = $this->createStub(FieldGroup::class);
        $group->method('getData')->willReturn('Renamed');
        $this->manager->expects($this->once())->method('save')
            ->with(['name' => 'Renamed', 'description' => ''], 4)->willReturn($group);

        $controller->execute();
    }

    public function testItReturnsToTheEditPageWithEveryProblemAndTheTypedValues(): void
    {
        $this->asserted = ['persistor'];
        $this->expectingMessages()->expects($this->exactly(2))->method('addErrorMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/edit', ['id' => 4])->willReturnSelf();
        $controller = $this->controller(['id' => '4', 'name' => '', 'description' => 'kept', 'fields' => '[{"id":"f_a"}]', 'translations' => '{}']);
        $this->manager->method('save')->willThrowException(new LocalizedException(__("Enter a name.\nAnother problem.")));
        $this->persistor->expects($this->once())->method('set')->with(
            \Furan\FormBuilder\Model\Admin\PersistKeys::FIELD_GROUP,
            ['name' => '', 'description' => 'kept', 'fields' => '[{"id":"f_a"}]', 'translations' => '{}']
        );

        $controller->execute();
    }

    public function testItRefusesFieldsThatCannotBeRead(): void
    {
        $this->asserted = ['manager', 'persistor'];
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage')->with(__('The group could not be read.'));
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/edit', ['id' => 4])->willReturnSelf();
        $controller = $this->controller(['id' => '4', 'name' => 'X', 'fields' => '{"not":"a list"}']);
        $this->manager->expects($this->never())->method('save');
        $this->persistor->expects($this->once())->method('set');

        $controller->execute();
    }

    public function testItRefusesFieldsOverThePayloadLimit(): void
    {
        $this->asserted = ['manager'];
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage')->with(__('This group is too large to save.'));
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/edit', ['id' => 4])->willReturnSelf();
        $big = '[' . str_repeat('1,', \Furan\FormBuilder\Model\FieldGroup\BuilderGroups::MAX_PAYLOAD_BYTES) . '1]';
        $controller = $this->controller(['id' => '4', 'name' => 'X', 'fields' => $big]);
        $this->manager->expects($this->never())->method('save');

        $controller->execute();
    }

    public function testItNeverCreatesAGroupFromThisForm(): void
    {
        $this->asserted = ['manager'];
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/')->willReturnSelf();
        $controller = $this->controller(['name' => 'New']);
        $this->manager->expects($this->never())->method('save');

        $controller->execute();
    }

    public function testItReportsAGroupThatNoLongerExists(): void
    {
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/')->willReturnSelf();
        $controller = $this->controller(['id' => '4', 'name' => 'X']);
        $this->manager->method('save')->willThrowException(new NoSuchEntityException(__('gone')));

        $controller->execute();
    }
}
