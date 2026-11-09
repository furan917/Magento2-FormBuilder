<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

class Result
{
    /**
     * @param array<string, string> $errors
     */
    private function __construct(
        private readonly bool $success,
        private readonly string $message,
        private readonly int $httpCode = 200,
        private readonly array $errors = [],
        private readonly ?string $redirect = null,
        private readonly ?string $reference = null
    ) {
    }

    public static function success(string $message, ?string $redirect, ?string $reference): self
    {
        return new self(true, $message, 200, [], $redirect, $reference);
    }

    public static function refused(string $message, int $httpCode): self
    {
        return new self(false, $message, $httpCode);
    }

    /**
     * @param array<string, string> $errors
     */
    public static function invalid(array $errors, ?string $message = null): self
    {
        return new self(false, $message ?? (string)__('Please fix the highlighted fields.'), 422, $errors);
    }

    public static function refresh(): self
    {
        return self::refused((string)__('Please refresh the page and try again.'), 400);
    }

    public static function tooMany(): self
    {
        return self::refused((string)__('Too many submissions. Please wait a few minutes and try again.'), 429);
    }

    public static function failed(): self
    {
        return self::refused((string)__('Something went wrong. Please try again.'), 500);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getRedirect(): ?string
    {
        return $this->redirect;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }
}
