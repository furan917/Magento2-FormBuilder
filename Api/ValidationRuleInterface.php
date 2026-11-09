<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

/** @api */
interface ValidationRuleInterface
{
    public function getCode(): string;

    public function getLabel(): string;

    /**
     * One of: none, int, number, string, regex, date, field.
     */
    public function getValueType(): string;

    /**
     * @param string|array<int|string, mixed> $value
     * @param array{values?: array<string, mixed>} $context
     */
    public function validate(string|array $value, mixed $ruleValue, array $context): bool;

    public function getDefaultMessage(mixed $ruleValue): string;
}
