<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Thread;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Submission\StatusWriter;
use Furan\FormBuilder\Model\Thread\AdminReply;
use Furan\FormBuilder\Model\Thread\AdminReplyMail;
use Furan\FormBuilder\Model\Thread\Messages;
use Magento\Framework\Exception\MailException;
use PHPUnit\Framework\TestCase;

class AdminReplyTest extends TestCase
{
    private SubmissionInterface $submission;
    private FormInterface $form;

    protected function setUp(): void
    {
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getId')->willReturn(7);
        $this->submission = $submission;
        $this->form = $this->createStub(FormInterface::class);
    }

    private function reply(Messages $messages, AdminReplyMail $mail, StatusWriter $writer): AdminReply
    {
        return new AdminReply($messages, $mail, $writer);
    }

    public function testItStoresEmailsAndMarksTheSubmissionAnsweredInOrder(): void
    {
        $order = [];
        $messages = $this->createMock(Messages::class);
        $messages->expects($this->once())->method('add')->with(7, 9, 'Hi')
            ->willReturnCallback(function () use (&$order): int {
                $order[] = 'add';
                return 55;
            });
        $messages->expects($this->once())->method('markEmailed')->with(55)
            ->willReturnCallback(function () use (&$order): void {
                $order[] = 'emailed';
            });
        $mail = $this->createMock(AdminReplyMail::class);
        $mail->expects($this->once())->method('send')->with($this->submission, $this->form, 'guest@example.com', 'Hi')
            ->willReturnCallback(function () use (&$order): void {
                $order[] = 'send';
            });
        $writer = $this->createMock(StatusWriter::class);
        $writer->expects($this->once())->method('change')->with([7], SubmissionInterface::STATUS_ANSWERED, 'admin')
            ->willReturnCallback(function () use (&$order): int {
                $order[] = 'answered';
                return 1;
            });

        $this->reply($messages, $mail, $writer)->send($this->submission, $this->form, 'guest@example.com', 'Hi', 9);

        $this->assertSame(['add', 'send', 'emailed', 'answered'], $order);
    }

    public function testItKeepsTheMessageUnsentAndTheStatusUnchangedWhenTheMailFails(): void
    {
        $messages = $this->createMock(Messages::class);
        $messages->expects($this->once())->method('add')->willReturn(55);
        $messages->expects($this->never())->method('markEmailed');
        $mail = $this->createStub(AdminReplyMail::class);
        $mail->method('send')->willThrowException(new MailException(__('down')));
        $writer = $this->createMock(StatusWriter::class);
        $writer->expects($this->never())->method('change');

        $this->expectException(MailException::class);
        $this->reply($messages, $mail, $writer)->send($this->submission, $this->form, 'guest@example.com', 'Hi', null);
    }
}
