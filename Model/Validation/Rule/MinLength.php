<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation\Rule;

class MinLength extends AbstractRule
{
    public function getCode(): string
    {
        return 'min_length';
    }

    public function getLabel(): string
    {
        return (string)__('Minimum length');
    }

    public function getValueType(): string
    {
        return 'int';
    }

    public function validate(string|array $value, mixed $ruleValue, array $context): bool
    {
        return is_string($value) && $this->length($value) >= (int)$ruleValue;
    }

    public function getDefaultMessage(mixed $ruleValue): string
    {
        return (string)__('Please enter at least %1 characters.', (int)$ruleValue);
    }
}
