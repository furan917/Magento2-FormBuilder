<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Action;

use Furan\FormBuilder\Api\ActionContext;
use Furan\FormBuilder\Api\ActionRetrySchedulerInterface;
use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\RetryableActionInterface;
use Furan\FormBuilder\Api\SubmissionActionInterface;
use Psr\Log\LoggerInterface;

/** Runs one action for the submit request and the queue consumer alike: never throws, retries only opted-in actions. */
class ActionExecutor
{
    private const MAX_ATTEMPTS_CAP = 5;

    public function __construct(
        private readonly ActionRetrySchedulerInterface $retryScheduler,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, string|array<int|string, mixed>> $values
     */
    public function run(
        string $key,
        SubmissionActionInterface $action,
        FormInterface $form,
        SubmissionInterface $submission,
        array $values,
        ActionContext $context
    ): void {
        try {
            $action->execute($form, $submission, $values, $context);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Form submission action ' . $action::class . ' failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
            $this->scheduleRetry($key, $action, (int)$submission->getId(), $context, $e);
        }
    }

    private function scheduleRetry(
        string $key,
        SubmissionActionInterface $action,
        int $submissionId,
        ActionContext $context,
        \Throwable $e
    ): void {
        $attempt = $context->attempt;
        if (!$action instanceof RetryableActionInterface || $submissionId <= 0 || !$action->isRetryable($e)) {
            return;
        }
        $max = max(1, min(self::MAX_ATTEMPTS_CAP, $action->maxAttempts()));
        if ($attempt >= $max) {
            $this->logger->warning(
                sprintf('Form submission action "%s" gave up after %d attempt(s).', $key, $attempt),
                ['submission_id' => $submissionId]
            );
            return;
        }
        try {
            $this->retryScheduler->schedule($submissionId, $key, $attempt, $e::class, $context->isEdit);
        } catch (\Throwable $scheduling) {
            $this->logger->error('Form submission action retry could not be scheduled: ' . $scheduling->getMessage());
        }
    }
}
