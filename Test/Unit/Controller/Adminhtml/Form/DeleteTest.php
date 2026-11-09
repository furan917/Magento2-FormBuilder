<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Form;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\Form\Delete;
use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Model\Form\Purger;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class DeleteTest extends ControllerTestCase
{
    public function testItDeletesAFormByIdAndRedirectsWithASuccessMessage(): void
    {
        $this->expectingRequest()->method('getParam')->with('id')->willReturn('7');
        $this->allow(true);
        $repository = $this->createMock(FormRepositoryInterface::class);
        $repository->expects($this->once())->method('getById')->with(7)->willReturn($this->createStub(Form::class));
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage');
        $this->expectingMessages()->expects($this->never())->method('addErrorMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/')->willReturnSelf();

        $controller = new Delete($this->context, $repository, $this->submissions(0));

        $this->assertInstanceOf(HttpPostActionInterface::class, $controller);
        $this->assertSame($this->redirect, $controller->execute());
    }

    public function testItReportsAnErrorWhenDeletingAnUnknownForm(): void
    {
        $this->expectingRequest()->method('getParam')->with('id')->willReturn('99');
        $this->allow(true);
        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getById')->willThrowException(new NoSuchEntityException(__('missing')));
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $this->expectingMessages()->expects($this->never())->method('addSuccessMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/')->willReturnSelf();

        $this->assertSame($this->redirect, (new Delete($this->context, $repository, $this->submissions(0)))->execute());
    }

    public function testItRefusesToDeleteAFormWithSubmissionsWithoutDeleteSubmissionsPermission(): void
    {
        $this->expectingRequest()->method('getParam')->with('id')->willReturn('7');
        $this->allow(false);
        $form = $this->createStub(Form::class);
        $form->method('getTitle')->willReturn('Contact');
        $repository = $this->createMock(FormRepositoryInterface::class);
        $repository->method('getById')->with(7)->willReturn($form);
        $repository->expects($this->never())->method('delete');
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage')->with(
            $this->callback(static fn ($m) => (string)$m === 'The form "Contact" has submissions. '
                . 'You need permission to delete submissions to delete it.')
        );
        $this->expectingMessages()->expects($this->never())->method('addSuccessMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/')->willReturnSelf();

        $this->assertSame($this->redirect, (new Delete($this->context, $repository, $this->submissions(3)))->execute());
    }

    public function testItDeletesAFormWithSubmissionsWhenPermitted(): void
    {
        $this->expectingRequest()->method('getParam')->with('id')->willReturn('7');
        $this->allow(true);
        $repository = $this->createMock(FormRepositoryInterface::class);
        $repository->expects($this->once())->method('getById')->with(7)->willReturn($this->createStub(Form::class));
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage');
        $this->expectingMessages()->expects($this->never())->method('addErrorMessage');
        $this->redirect->method('setPath')->willReturnSelf();

        (new Delete($this->context, $repository, $this->submissions(3)))->execute();
    }

    private function allow(bool $allowed): void
    {
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(
            static fn ($r) => $r !== 'Furan_FormBuilder::submissions_delete' || $allowed
        );
        $this->context->method('getAuthorization')->willReturn($auth);
    }

    private function submissions(int $size, bool $inline = true): Purger
    {
        $purger = $this->createMock(Purger::class);
        $purger->method('hasSubmissions')->with(7)->willReturn($size > 0);
        $purger->method('deleteOrSchedule')->willReturnCallback(function ($form) use ($inline): bool {
            $this->deleted[] = $form;
            return $inline;
        });
        return $purger;
    }

    private array $deleted = [];

    public function testItLeavesALargeFormToTheBackgroundPurgeAndSaysSo(): void
    {
        $this->expectingRequest()->method('getParam')->with('id')->willReturn('7');
        $this->allow(true);
        $form = $this->createStub(Form::class);
        $repository = $this->createMock(FormRepositoryInterface::class);
        $repository->method('getById')->with(7)->willReturn($form);
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage')->with(
            $this->callback(static fn ($m) => str_contains((string)$m, 'deleted in the background'))
        );
        $this->redirect->method('setPath')->willReturnSelf();

        (new Delete($this->context, $repository, $this->submissions(9000, false)))->execute();

        $this->assertSame([$form], $this->deleted);
    }
}
