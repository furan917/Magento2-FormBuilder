<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

/**
 * Opt-in retries for a failed action. An action that sends must retry only failures where nothing was sent.
 *
 * @api
 */
interface RetryableActionInterface
{
    /** Total runs including the first; clamped to 1..5. */
    public function maxAttempts(): int;

    /** Whether this failure is retried; false runs no further attempt. */
    public function isRetryable(\Throwable $failure): bool;
}
