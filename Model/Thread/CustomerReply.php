<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Thread;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Customer\Impersonation;
use Psr\Log\LoggerInterface;

/**
 * Callers have already checked access. The rate limit runs last, so refused posts never use up the allowance.
 * Status is not locked and thread size is counted before the insert: an accepted race.
 */
class CustomerReply
{
    private const AUTHOR_CUSTOMER = 'customer';
    private const AUTHOR_GUEST = 'guest';

    public function __construct(
        private readonly Messages $messages,
        private readonly CustomerReplyNotifier $notifier,
        private readonly ReplyPolicy $policy,
        private readonly ThreadLimits $limits,
        private readonly Impersonation $impersonation,
        private readonly MessageSanitizer $sanitizer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function postAsCustomer(
        SubmissionInterface $submission,
        FormInterface $form,
        string $rawBody,
        string $notifyEmail,
        ?int $storeId,
        int $customerId
    ): ReplyOutcome {
        return $this->post(
            $submission,
            $form,
            self::AUTHOR_CUSTOMER,
            $rawBody,
            $notifyEmail,
            $this->policy->customerRepliesOpen($form->getSettings(), $storeId),
            fn (): bool => $this->limits->allowCustomerReply($customerId)
        );
    }

    public function postAsGuest(
        SubmissionInterface $submission,
        FormInterface $form,
        string $rawBody,
        string $notifyEmail,
        ?int $storeId
    ): ReplyOutcome {
        return $this->post(
            $submission,
            $form,
            self::AUTHOR_GUEST,
            $rawBody,
            $notifyEmail,
            $this->policy->guestRepliesOpen($form->getSettings(), $storeId),
            fn (): bool => $this->limits->allowGuestReply((int)$submission->getId())
        );
    }

    /** @param callable(): bool $withinLimit counts the attempt */
    private function post(
        SubmissionInterface $submission,
        FormInterface $form,
        string $author,
        string $rawBody,
        string $notifyEmail,
        bool $repliesOpen,
        callable $withinLimit
    ): ReplyOutcome {
        $submissionId = (int)$submission->getId();
        $body = $this->sanitizer->clean($rawBody);
        $length = mb_strlen($body);
        $refusal = match (true) {
            $this->impersonation->isActive() => ReplyOutcome::refused(
                403,
                (string)__('Replies cannot be sent while you are logged in as this customer.')
            ),
            $length === 0 || $length > Messages::MAX_BODY_LENGTH => ReplyOutcome::refused(
                422,
                (string)__('Please enter a reply of up to %1 characters.', Messages::MAX_BODY_LENGTH)
            ),
            (int)$submission->getStatus() === SubmissionInterface::STATUS_CLOSED => ReplyOutcome::refused(
                409,
                (string)__('This conversation is closed.')
            ),
            !$repliesOpen => ReplyOutcome::refused(409, (string)__('Replies are closed for this form.')),
            $this->policy->isFull($submissionId) => ReplyOutcome::refused(409, ReplyPolicy::fullMessage()),
            !$withinLimit() => ReplyOutcome::refused(429, (string)__('Too many replies. Please try again later.')),
            default => null,
        };
        if ($refusal !== null) {
            return $refusal;
        }

        $messageId = $this->messages->addCustomer($submissionId, $body, $author);
        if ((int)$submission->getStatus() === SubmissionInterface::STATUS_ANSWERED) {
            $this->messages->reopenIfAnswered($submissionId);
        }
        try {
            $this->notifier->notify($form, $submission, $messageId, $notifyEmail, $body);
        } catch (\Throwable $e) {
            $this->logger->error('Furan_FormBuilder: customer reply notification skipped: ' . $e::class);
        }

        return ReplyOutcome::sent();
    }
}
