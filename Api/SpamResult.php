<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

use Magento\Framework\Phrase;

/**
 * Outcome of a spam check. A silent result looks like a normal success to the visitor but nothing is stored,
 * no action runs and no email is sent, so a bot cannot tell it was caught.
 *
 * @api
 */
class SpamResult
{
    private const PASS = 'pass';
    private const REJECT = 'reject';
    private const SILENT = 'silent';

    private function __construct(
        private readonly string $outcome,
        private readonly string $code,
        private readonly ?Phrase $message,
        private readonly int $httpCode
    ) {
    }

    public static function pass(): self
    {
        return new self(self::PASS, '', null, 200);
    }

    /** A null message shows the generic "refresh the page" text. */
    public static function reject(string $code, ?Phrase $message = null, int $httpCode = 400): self
    {
        return new self(self::REJECT, $code, $message, $httpCode);
    }

    public static function silent(string $code): self
    {
        return new self(self::SILENT, $code, null, 200);
    }

    public function isPass(): bool
    {
        return $this->outcome === self::PASS;
    }

    public function isReject(): bool
    {
        return $this->outcome === self::REJECT;
    }

    public function isSilent(): bool
    {
        return $this->outcome === self::SILENT;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getMessage(): ?Phrase
    {
        return $this->message;
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }
}
