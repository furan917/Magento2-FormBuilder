<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Account;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Controller\Account\Reply;
use Furan\FormBuilder\Model\Account\SubmissionReader;
use Furan\FormBuilder\Model\Thread\CustomerReply;
use Furan\FormBuilder\Model\Thread\ReplyOutcome;
use Magento\Customer\Api\Data\CustomerInterface;
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
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ReplyTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private SubmissionReader&Stub $reader;
    private CustomerReply&Stub $reply;
    private Validator&Stub $formKey;
    private Http&Stub $request;
    private Forward&Stub $forward;
    private Redirect&Stub $redirect;
    private Json&Stub $json;
    private ManagerInterface&Stub $manager;
    private SubmissionInterface&Stub $submission;
    private FormInterface&Stub $form;
    private Reply $controller;

    private function build(string $body = 'Thanks!', bool $ajax = false, mixed $customerId = '5'): void
    {
        $this->reader = $this->double('reader', SubmissionReader::class);
        $this->reply = $this->double('reply', CustomerReply::class);
        $this->formKey = $this->double('formKey', Validator::class);
        $this->request = $this->createStub(Http::class);
        $this->request->method('getParam')->willReturnMap([['id', null, '9'], ['body', null, $body]]);
        $this->request->method('isXmlHttpRequest')->willReturn($ajax);
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
        $this->manager = $this->double('manager', ManagerInterface::class);
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('cust@x.test');
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturn($customerId);
        $session->method('getCustomerData')->willReturn($customer);
        $this->submission = $this->createStub(SubmissionInterface::class);
        $this->submission->method('getFormId')->willReturn(3);
        $submissions = $this->createStub(SubmissionRepositoryInterface::class);
        $submissions->method('getById')->willReturn($this->submission);
        $forms = $this->createStub(FormRepositoryInterface::class);
        $this->form = $this->createStub(FormInterface::class);
        $forms->method('getById')->willReturn($this->form);

        $this->controller = new Reply(
            $redirectFactory,
            $forwardFactory,
            $jsonFactory,
            $session,
            $this->request,
            $this->reader,
            $forms,
            $submissions,
            $this->reply,
            $this->formKey,
            $this->manager
        );
    }

    private function ownRow(string $status = '0'): void
    {
        $this->reader->method('find')->with(5, 9)->willReturn(['submission_id' => '9', 'form_id' => '3', 'status' => $status, 'store_id' => '4']);
    }

    public function testItPostsTheBodyAsTheOwnerAndRedirectsWithASuccessMessage(): void
    {
        $this->asserted = ['reader', 'reply', 'manager'];
        $this->build('  Thanks!  ');
        $this->ownRow();
        $this->reply->expects($this->once())->method('postAsCustomer')
            ->with($this->submission, $this->form, '  Thanks!  ', 'cust@x.test', 4, 5)
            ->willReturn(ReplyOutcome::sent());
        $this->manager->expects($this->once())->method('addSuccessMessage')->with('Your reply has been sent.');

        $this->assertSame($this->redirect, $this->controller->execute());
    }

    public function testItAnswersAnAjaxReplyWithJson(): void
    {
        $this->asserted = ['reader', 'json'];
        $this->build('Hi', true);
        $this->ownRow();
        $this->reply->method('postAsCustomer')->willReturn(ReplyOutcome::sent());
        $this->json->expects($this->never())->method('setHttpResponseCode');
        $this->json->expects($this->once())->method('setData')
            ->with(['success' => true, 'message' => 'Your reply has been sent.']);

        $this->assertSame($this->json, $this->controller->execute());
    }

    public function testItMapsARefusalToItsHttpCodeForAjaxAndToAnErrorMessageOtherwise(): void
    {
        $this->asserted = ['reader', 'json'];
        $this->build('Hi', true);
        $this->ownRow();
        $this->reply->method('postAsCustomer')->willReturn(ReplyOutcome::refused(429, 'Too many replies.'));
        $this->json->expects($this->once())->method('setHttpResponseCode')->with(429);
        $this->json->expects($this->once())->method('setData')
            ->with(['success' => false, 'message' => 'Too many replies.']);
        $this->assertSame($this->json, $this->controller->execute());

        $this->asserted = ['reader', 'manager'];
        $this->build('Hi');
        $this->ownRow();
        $this->reply->method('postAsCustomer')->willReturn(ReplyOutcome::refused(409, 'This conversation is closed.'));
        $this->manager->expects($this->once())->method('addErrorMessage')->with('This conversation is closed.');
        $this->assertSame($this->redirect, $this->controller->execute());
    }

    public function testItRefusesAReplyFromAnotherCustomer(): void
    {
        $this->asserted = ['reader', 'reply', 'forward'];
        $this->build();
        $this->reader->method('find')->with(5, 9)->willReturn(null);
        $this->forward->expects($this->once())->method('forward')->with('noroute')->willReturnSelf();
        $this->reply->expects($this->never())->method('postAsCustomer');

        $this->assertSame($this->forward, $this->controller->execute());
    }

    public function testItRefusesWithoutACustomerSession(): void
    {
        $this->asserted = ['reader', 'forward'];
        $this->build('x', false, null);
        $this->reader->expects($this->never())->method('find');
        $this->forward->expects($this->once())->method('forward')->with('noroute')->willReturnSelf();

        $this->assertSame($this->forward, $this->controller->execute());
    }

    public function testItRejectsAnAjaxReplyWithoutAFormKey(): void
    {
        $this->asserted = ['formKey', 'json'];
        $this->build('x', true);
        $this->json->expects($this->once())->method('setHttpResponseCode')->with(403);

        $exception = $this->controller->createCsrfValidationException($this->request);

        $this->assertInstanceOf(InvalidRequestException::class, $exception);
        $this->assertSame($this->json, $exception->getReplaceResult());
        $this->formKey->method('validate')->with($this->request)->willReturn(false);
        $this->assertFalse($this->controller->validateForCsrf($this->request));
        $this->assertInstanceOf(\Magento\Framework\App\Action\HttpPostActionInterface::class, $this->controller);
        $this->assertInstanceOf(\Magento\Customer\Controller\AccountInterface::class, $this->controller);
    }
}
