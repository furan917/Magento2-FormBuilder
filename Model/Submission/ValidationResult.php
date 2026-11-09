<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

class ValidationResult
{
    /**
     * @param array<string, string|array<int|string, mixed>> $values
     * @param array<string, string> $errors
     * @param array<string, array<string, mixed>> $files
     * @param string[] $unansweredFiles visible file fields posted without an upload
     */
    public function __construct(
        private readonly array $values = [],
        private readonly array $errors = [],
        private readonly array $files = [],
        private readonly array $unansweredFiles = []
    ) {
    }

    /**
     * @return array<string, string|array<int|string, mixed>>
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * @return string[]
     */
    public function getUnansweredFiles(): array
    {
        return $this->unansweredFiles;
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
