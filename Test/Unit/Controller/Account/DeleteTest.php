<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Account;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Account\Delete;
use Furan\FormBuilder\Model\Account\CustomerSubmissionDeleter;
use Furan\FormBuilder\Model\Account\SubmissionPolicy;
use Furan\FormBuilder\Model\Account\SubmissionReader;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Furan\FormBuilder\Model\Customer\Impersonation;
use Psr\Log\LoggerInterface;

class DeleteTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private SubmissionReader&Stub $reader;
    private FormRepositoryInterface&Stub $forms;
    private CustomerSubmissionDeleter&Stub $deleter;
    private Validator&Stub $formKey;
    private Http&MockObject $request;
    private Forward&Stub $forward;
    private Redirect&Stub $redirect;
    private Json&Stub $json;
    private ManagerInterface&Stub $messages;
    private Delete $controller;
    private bool $impersonating = false;

    private function impersonation(): Impersonation
    {
        $impersonation = $this->createStub(Impersonation::class);
        $impersonation->method('isActive')->willReturn($this->impersonating);
        $impersonation->method('accountRefusal')->willReturn('Not while impersonating.');
        return $impersonation;
    }

    private function build(mixed $customerId = '5', array $settings = []): void
    {
        $this->reader = $this->double('reader', SubmissionReader::class);
        $this->forms = $this->createStub(FormRepositoryInterface::class);
        $this->deleter = $this->double('deleter', CustomerSubmissionDeleter::class);
        $this->formKey = $this->double('formKey', Validator::class);
        $this->request = $this->createMock(Http::class);
        $this->request->method('getParam')->with('id')->willReturn('9');
        $this->forward = $this->double('forward', Forward::class);
        $forwardFactory = $this->createStub(ForwardFactory::class);
        $forwardFactory->method('create')->willReturn($this->forward);
        $this->redirect = $this->createStub(Redirect::class);
        $this->redirect->method('setPath')->willReturnSelf();
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);
        $this->json = $this->double('json', Json::class);
        $this->json->method('setHttpResponseCode')->willReturnSelf();
        $this->json->method('setData')->willReturnSelf();
        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($this->json);
        $this->messages = $this->double('messages', ManagerInterface::class);
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturn($customerId);
        $form = $this->createStub(FormInterface::class);
        $form->method('getSettings')->willReturn($settings);
        $this->forms->method('getById')->willReturn($form);

        $this->controller = new Delete(
            $redirectFactory,
            $forwardFactory,
            $jsonFactory,
            $session,
            $this->request,
            $this->reader,
            $this->forms,
            new SubmissionPolicy(),
            $this->deleter,
            $this->formKey,
            $this->messages,
            $this->createStub(LoggerInterface::class),
            $this->impersonation()
        );
    }

    private function ownRow(): void
    {
        $this->reader->method('find')->with(5, 9)->willReturn(['submission_id' => '9', 'form_id' => '3', 'status' => '0']);
    }

    public function testItDeletesTheCustomersOwnSubmission(): void
    {
        $this->asserted = ['reader', 'deleter', 'messages'];
        $this->build();
        $this->ownRow();
        $this->deleter->expects($this->once())->method('delete')->with(9);
        $this->messages->expects($this->once())->method('addSuccessMessage');

        $this->assertSame($this->redirect, $this->controller->execute());
    }

    public function testItReportsAFailedDeleteBackOnTheList(): void
    {
        $this->asserted = ['reader', 'messages'];
        $this->build();
        $this->ownRow();
        $this->deleter->method('delete')->willThrowException(new CouldNotDeleteException(__('x')));
        $this->messages->expects($this->once())->method('addErrorMessage');
        $this->messages->expects($this->never())->method('addSuccessMessage');

        $this->assertSame($this->redirect, $this->controller->execute());
    }

    public function testItRefusesToDeleteAnotherCustomersSubmission(): void
    {
        $this->asserted = ['reader', 'deleter', 'forward'];
        $this->build();
        $this->reader->method('find')->with(5, 9)->willReturn(null);
        $this->forward->expects($this->once())->method('forward')->with('noroute')->willReturnSelf();
        $this->deleter->expects($this->never())->method('delete');

        $this->assertSame($this->forward, $this->controller->execute());
    }

    public function testItRefusesWithoutACustomerSession(): void
    {
        $this->asserted = ['reader', 'forward'];
        $this->build(null);
        $this->reader->expects($this->never())->method('find');
        $this->forward->expects($this->once())->method('forward')->with('noroute')->willReturnSelf();

        $this->assertSame($this->forward, $this->controller->execute());
    }

    public function testItRefusesEditAndDeleteWhenTheFormDoesNotAllowThem(): void
    {
        $this->asserted = ['reader', 'deleter', 'forward'];
        $this->build('5', ['account_delete' => false]);
        $this->ownRow();
        $this->forward->expects($this->once())->method('forward')->with('noroute')->willReturnSelf();
        $this->deleter->expects($this->never())->method('delete');

        $this->assertSame($this->forward, $this->controller->execute());
    }

    public function testItRequiresAPostWithAValidFormKeyToDelete(): void
    {
        $this->asserted = ['formKey'];
        $this->build();
        $this->formKey->method('validate')->with($this->request)->willReturnOnConsecutiveCalls(true, false);

        $this->assertTrue($this->controller->validateForCsrf($this->request));
        $this->assertFalse($this->controller->validateForCsrf($this->request));
        $this->assertInstanceOf(\Magento\Framework\App\Action\HttpPostActionInterface::class, $this->controller);
    }

    public function testItRejectsAnAjaxDeleteWithoutAFormKey(): void
    {
        $this->asserted = ['json'];
        $this->build();
        $this->request->method('isXmlHttpRequest')->willReturn(true);
        $this->json->expects($this->once())->method('setHttpResponseCode')->with(403);

        $exception = $this->controller->createCsrfValidationException($this->request);

        $this->assertInstanceOf(InvalidRequestException::class, $exception);
        $this->assertSame($this->json, $exception->getReplaceResult());
    }

    public function testItRedirectsAPlainDeleteWithoutAFormKeyBackToTheListWithTheReason(): void
    {
        $this->asserted = ['messages'];
        $this->build();
        $this->request->method('isXmlHttpRequest')->willReturn(false);
        $this->messages->expects($this->once())->method('addErrorMessage')
            ->with('Your session has expired. Please refresh the page and try again.');

        $exception = $this->controller->createCsrfValidationException($this->request);

        $this->assertSame($this->redirect, $exception->getReplaceResult());
    }

    public function testItRefusesTheDeleteWhileAnAdminIsLoggedInAsTheCustomer(): void
    {
        $this->asserted = ['reader', 'deleter', 'messages'];
        $this->impersonating = true;
        $this->build();
        $this->ownRow();
        $this->deleter->expects($this->never())->method('delete');
        $this->messages->expects($this->once())->method('addErrorMessage')->with('Not while impersonating.');

        $this->assertSame($this->redirect, $this->controller->execute());
    }
}
