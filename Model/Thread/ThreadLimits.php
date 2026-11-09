<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Thread;

use Furan\FormBuilder\Model\Spam\RateLimiter;

/** Throttles guest link guessing (per client IP) and replies (guests per submission, customers per account; each one mails the merchant). */
class ThreadLimits
{
    public const TOKEN_ATTEMPTS = 30;
    private const TOKEN_WINDOW = 600;
    public const REPLIES = 10;
    private const REPLY_WINDOW = 3600;

    public function __construct(private readonly RateLimiter $rateLimiter)
    {
    }

    public function allowTokenAttempt(): bool
    {
        return $this->rateLimiter->allow('thread_token', self::TOKEN_ATTEMPTS, self::TOKEN_WINDOW);
    }

    public function allowGuestReply(int $submissionId): bool
    {
        return $this->rateLimiter->hit('thread_reply_' . $submissionId, self::REPLIES, self::REPLY_WINDOW);
    }

    public function allowCustomerReply(int $customerId): bool
    {
        return $this->rateLimiter->hit('customer_reply_' . $customerId, self::REPLIES, self::REPLY_WINDOW);
    }
}
