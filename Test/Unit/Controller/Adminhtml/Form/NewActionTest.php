<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form;

use Furan\FormBuilder\Controller\Adminhtml\Form\NewAction;
use Magento\Backend\Model\View\Result\Forward;
use Magento\Backend\Model\View\Result\ForwardFactory;

class NewActionTest extends ControllerTestCase
{
    public function testItForwardsNewToEdit(): void
    {
        $forward = $this->createMock(Forward::class);
        $forward->expects($this->once())->method('forward')->with('edit')->willReturnSelf();
        $factory = $this->createStub(ForwardFactory::class);
        $factory->method('create')->willReturn($forward);

        $this->assertSame($forward, (new NewAction($this->context, $factory))->execute());
    }
}
