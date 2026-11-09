<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation\Rule;

class Matches extends AbstractRule
{
    public function getCode(): string
    {
        return 'matches';
    }

    public function getLabel(): string
    {
        return (string)__('Matches field');
    }

    public function getValueType(): string
    {
        return 'field';
    }

    public function validate(string|array $value, mixed $ruleValue, array $context): bool
    {
        return is_string($value) && isset($context['values'][(string)$ruleValue]) && $value === (string)$context['values'][(string)$ruleValue];
    }

    public function getDefaultMessage(mixed $ruleValue): string
    {
        return (string)__('This must match the %1 field.', (string)$ruleValue);
    }
}
