<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

/** @api */
interface ActionRetrySchedulerInterface
{
    /**
     * Called after a retryable action failed and has attempts left.
     *
     * @param int $attempt the attempt that just failed (1-based); the retry is attempt + 1
     * @param string $error exception class name, never a message that could hold answers
     * @param bool $isEdit whether the failed run was for a submission edit
     */
    public function schedule(int $submissionId, string $actionKey, int $attempt, string $error, bool $isEdit): void;
}
