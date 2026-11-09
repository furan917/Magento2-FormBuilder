<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\Submission\Reply;
use Furan\FormBuilder\Model\Thread\AdminReply;
use Furan\FormBuilder\Model\Thread\MessageSanitizer;
use Furan\FormBuilder\Model\Thread\RecipientResolver;
use Magento\Backend\Model\Auth;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\Stub;

class ReplyTest extends SubmissionControllerTestCase
{
    private AdminReply&Stub $reply;
    private SubmissionInterface&Stub $submission;
    private FormInterface&Stub $form;
    private CustomerRepositoryInterface&Stub $customers;
    private ?SubmissionRepositoryInterface $submissions = null;
    private array $params = ['id' => '7', 'body' => 'Hello <b>there</b>'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->request->method('getParam')->willReturnCallback(fn(string $key) => $this->params[$key] ?? null);
        $this->redirect->method('setPath')->willReturnSelf();
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(9);
        $auth = $this->createStub(Auth::class);
        $auth->method('getUser')->willReturn($user);
        $this->context->method('getAuth')->willReturn($auth);

        $this->reply = $this->createStub(AdminReply::class);
        $this->customers = $this->createStub(CustomerRepositoryInterface::class);
        $this->submission = $this->createStub(SubmissionInterface::class);
        $this->submission->method('getId')->willReturn(7);
        $this->submission->method('getFormId')->willReturn(3);
        $this->submission->method('getPayload')->willReturn([
            ['name' => 'contact', 'label' => 'Contact', 'type' => 'email', 'value' => 'guest@example.com'],
        ]);
        $this->form = $this->createStub(FormInterface::class);
        $this->form->method('getSettings')->willReturn(['autoresponder_email_field' => 'contact']);
        $this->form->method('getSchema')->willReturn(['pages' => [['items' => [
            ['kind' => 'field', 'type' => 'email', 'name' => 'contact'],
        ]]]]);
    }

    private function controller(): Reply
    {
        if ($this->submissions === null) {
            $this->submissions = $this->createStub(SubmissionRepositoryInterface::class);
            $this->submissions->method('getById')->willReturn($this->submission);
        }
        $forms = $this->createStub(FormRepositoryInterface::class);
        $forms->method('getById')->willReturn($this->form);

        return new Reply(
            $this->context,
            $this->submissions,
            $forms,
            new RecipientResolver($this->customers),
            new MessageSanitizer(),
            $this->reply
        );
    }

    private function expectingReply(): AdminReply&\PHPUnit\Framework\MockObject\MockObject
    {
        $reply = $this->createMock(AdminReply::class);
        $this->reply = $reply;
        return $reply;
    }

    public function testItSendsTheReplyAsTheLoggedInAdminAndConfirmsIt(): void
    {
        $this->expectingReply()->expects($this->once())->method('send')
            ->with($this->submission, $this->form, 'guest@example.com', 'Hello <b>there</b>', 9);
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/view', ['id' => 7])->willReturnSelf();

        $this->assertSame($this->redirect, $this->controller()->execute());
    }

    public function testItEmailsTheLiveCustomerAccountAddressWhenLinked(): void
    {
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getId')->willReturn(7);
        $submission->method('getFormId')->willReturn(3);
        $submission->method('getCustomerId')->willReturn(12);
        $this->submission = $submission;
        $customers = $this->customers = $this->createMock(CustomerRepositoryInterface::class);
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('account@example.com');
        $customers->expects($this->once())->method('getById')->with(12)->willReturn($customer);
        $this->expectingReply()->expects($this->once())->method('send')
            ->with($submission, $this->form, 'account@example.com', $this->anything(), 9);

        $this->controller()->execute();
    }

    public function testItWarnsThatTheReplyWasSavedWhenTheMailFails(): void
    {
        $this->reply->method('send')->willThrowException(new MailException(__('down')));
        $messages = $this->expectingMessages();
        $messages->expects($this->once())->method('addErrorMessage')->with($this->callback(
            static fn ($m) => (string)$m === 'The reply was saved but the email could not be sent. Please check the mail settings.'
        ));
        $messages->expects($this->never())->method('addSuccessMessage');

        $this->controller()->execute();
    }

    public function testItRefusesRepliesWhenTheSubmissionHasNoEmail(): void
    {
        $this->form = $this->createStub(FormInterface::class);
        $this->form->method('getSettings')->willReturn([]);
        $this->form->method('getSchema')->willReturn(['pages' => []]);
        $this->expectingReply()->expects($this->never())->method('send');
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');

        $this->controller()->execute();
    }

    public function testItRedirectsToTheGridForAnUnknownSubmission(): void
    {
        $submissions = $this->createStub(SubmissionRepositoryInterface::class);
        $submissions->method('getById')->willThrowException(new NoSuchEntityException(__('missing')));
        $this->submissions = $submissions;
        $this->expectingReply()->expects($this->never())->method('send');
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/')->willReturnSelf();

        $this->controller()->execute();
    }

    public function testItSendsTheSanitisedReply(): void
    {
        $this->params['body'] = "Hello\u{202E} there  \r\n\r\n\r\n\u{200B}Bye";
        $this->expectingReply()->expects($this->once())->method('send')
            ->with($this->anything(), $this->anything(), $this->anything(), "Hello there\n\nBye", 9);

        $this->controller()->execute();
    }

    public function testItRejectsAReplyThatIsEmptyAfterSanitising(): void
    {
        $this->params['body'] = "\u{200B}\u{FEFF}";
        $this->expectingReply()->expects($this->never())->method('send');

        $this->controller()->execute();
    }

    public function testItRefusesAReplyLongerThanTheLimitWithoutStoringIt(): void
    {
        $this->params['body'] = str_repeat('a', 5001);
        $this->expectingReply()->expects($this->never())->method('send');
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage')
            ->with($this->callback(static fn ($m) => (string)$m === 'Please enter a reply of up to 5000 characters.'));

        $this->controller()->execute();
    }
}
