<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Thread;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Model\Thread\CustomerReplyNotifier;
use Furan\FormBuilder\Model\Thread\Messages;
use Furan\FormBuilder\Model\Thread\QueuedCustomerReply;
use Furan\FormBuilder\Model\Thread\RecipientResolver;
use Furan\FormBuilder\Test\Unit\Model\Submission\SubmissionStub;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class QueuedCustomerReplyTest extends TestCase
{
    private Messages&Stub $messages;
    private SubmissionRepositoryInterface&Stub $submissions;
    private FormRepositoryInterface&Stub $forms;
    private RecipientResolver&Stub $recipients;

    protected function setUp(): void
    {
        $this->messages = $this->createStub(Messages::class);
        $this->submissions = $this->createStub(SubmissionRepositoryInterface::class);
        $this->forms = $this->createStub(FormRepositoryInterface::class);
        $this->recipients = $this->createStub(RecipientResolver::class);
    }

    private function queued(CustomerReplyNotifier $notifier): QueuedCustomerReply
    {
        return new QueuedCustomerReply($this->messages, $this->submissions, $this->forms, $this->recipients, $notifier);
    }

    public function testItSendsTheStoredReplyWithTheThreadRecipientAsReplyTo(): void
    {
        $submission = (new SubmissionStub())->setFormId(7);
        $form = $this->createStub(FormInterface::class);
        $this->messages->method('customerMessage')->willReturnMap([[31, ['submission_id' => 9, 'body' => 'Hi']]]);
        $this->submissions->method('getById')->willReturnMap([[9, $submission]]);
        $this->forms->method('getById')->willReturnMap([[7, $form]]);
        $this->recipients->method('resolve')->willReturn('cust@x.test');
        $notifier = $this->createMock(CustomerReplyNotifier::class);
        $notifier->expects($this->once())->method('send')->with($form, $submission, 'cust@x.test', 'Hi');

        $this->queued($notifier)->send(31);
    }

    public function testItSendsWithoutAReplyToWhenTheThreadHasNoRecipient(): void
    {
        $this->messages->method('customerMessage')->willReturn(['submission_id' => 9, 'body' => 'Hi']);
        $this->submissions->method('getById')->willReturn(new SubmissionStub());
        $this->forms->method('getById')->willReturn($this->createStub(FormInterface::class));
        $this->recipients->method('resolve')->willReturn(null);
        $notifier = $this->createMock(CustomerReplyNotifier::class);
        $notifier->expects($this->once())->method('send')->with($this->anything(), $this->anything(), '', 'Hi');

        $this->queued($notifier)->send(31);
    }

    public function testItDropsAMessageThatIsGoneOrNotFromTheCustomer(): void
    {
        $this->messages->method('customerMessage')->willReturn(null);
        $notifier = $this->createMock(CustomerReplyNotifier::class);
        $notifier->expects($this->never())->method('send');

        $this->queued($notifier)->send(31);
        $this->queued($notifier)->send(0);
    }

    public function testItDropsAReplyWhoseSubmissionWasDeleted(): void
    {
        $this->messages->method('customerMessage')->willReturn(['submission_id' => 9, 'body' => 'Hi']);
        $this->submissions->method('getById')->willThrowException(new NoSuchEntityException());
        $notifier = $this->createMock(CustomerReplyNotifier::class);
        $notifier->expects($this->never())->method('send');

        $this->queued($notifier)->send(31);
    }
}
