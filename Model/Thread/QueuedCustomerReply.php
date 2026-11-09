<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Thread;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/** Sends a customer reply notification queued as a message id; the reply-to is the thread's recipient. */
class QueuedCustomerReply
{
    public function __construct(
        private readonly Messages $messages,
        private readonly SubmissionRepositoryInterface $submissionRepository,
        private readonly FormRepositoryInterface $formRepository,
        private readonly RecipientResolver $recipients,
        private readonly CustomerReplyNotifier $notifier
    ) {
    }

    public function send(int $messageId): void
    {
        $message = $messageId > 0 ? $this->messages->customerMessage($messageId) : null;
        if ($message === null) {
            return;
        }
        try {
            $submission = $this->submissionRepository->getById($message['submission_id']);
            $form = $this->formRepository->getById($submission->getFormId());
        } catch (NoSuchEntityException) {
            return;
        }
        $this->notifier->send(
            $form,
            $submission,
            (string)$this->recipients->resolve($submission, $form),
            $message['body']
        );
    }
}
