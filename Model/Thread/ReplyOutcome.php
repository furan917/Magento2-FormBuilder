<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Thread;

/** Result of a customer or guest reply: stored, or refused with an HTTP-style code and a shopper-facing message. */
class ReplyOutcome
{
    private function __construct(
        public readonly int $code,
        public readonly string $message
    ) {
    }

    public static function sent(): self
    {
        return new self(200, (string)__('Your reply has been sent.'));
    }

    public static function refused(int $code, string $message): self
    {
        return new self($code, $message);
    }

    public function isSent(): bool
    {
        return $this->code === 200;
    }
}
