<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\FieldGroup;

use Furan\FormBuilder\Controller\Adminhtml\FieldGroup\Delete;
use Furan\FormBuilder\Model\FieldGroup\FieldGroupManager;
use Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form\ControllerTestCase;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class DeleteTest extends ControllerTestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private FieldGroupManager $manager;

    private function controller(string $id, bool $allowed = true): Delete
    {
        $this->expectingRequest()->method('getParam')->with('id')->willReturn($id);
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(static fn (string $resource) => $allowed || $resource !== 'Furan_FormBuilder::field_groups_delete');
        $this->context->method('getAuthorization')->willReturn($auth);
        $this->manager = $this->double('manager', FieldGroupManager::class);
        return new Delete($this->context, $this->manager);
    }

    public function testItDeletesAGroupWithTheDeletePermission(): void
    {
        $this->asserted = ['manager'];
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/')->willReturnSelf();
        $controller = $this->controller('4');
        $this->manager->expects($this->once())->method('delete')->with(4);

        $this->assertSame('Furan_FormBuilder::field_groups_delete', Delete::ADMIN_RESOURCE);
        $this->assertTrue((new \ReflectionMethod($controller, '_isAllowed'))->invoke($controller));
        $controller->execute();
    }

    public function testItRefusesToDeleteWithoutTheDeletePermission(): void
    {
        $controller = $this->controller('4', false);

        $this->assertFalse((new \ReflectionMethod($controller, '_isAllowed'))->invoke($controller));
    }

    public function testItReportsAGroupThatNoLongerExists(): void
    {
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $this->expectingMessages()->expects($this->never())->method('addSuccessMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/')->willReturnSelf();
        $controller = $this->controller('99');
        $this->manager->method('delete')->willThrowException(new NoSuchEntityException(__('gone')));

        $controller->execute();
    }
}
