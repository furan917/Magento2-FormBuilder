<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Thread;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Submission\StatusWriter;
use Magento\Framework\Exception\MailException;

/** Callers have checked the ACL, sanitised the body and resolved the recipient. */
class AdminReply
{
    public function __construct(
        private readonly Messages $messages,
        private readonly AdminReplyMail $mail,
        private readonly StatusWriter $statusWriter
    ) {
    }

    /** @throws MailException after the message is stored, which then stays unsent and the status unchanged */
    public function send(
        SubmissionInterface $submission,
        FormInterface $form,
        string $recipient,
        string $body,
        ?int $adminUserId
    ): void {
        $submissionId = (int)$submission->getId();
        $messageId = $this->messages->add($submissionId, $adminUserId, $body);
        $this->mail->send($submission, $form, $recipient, $body);
        $this->messages->markEmailed($messageId);
        $this->statusWriter->change([$submissionId], SubmissionInterface::STATUS_ANSWERED, 'admin');
    }
}
