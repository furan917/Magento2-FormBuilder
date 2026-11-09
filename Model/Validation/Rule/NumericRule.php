<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation\Rule;

use Furan\FormBuilder\Model\Validation\Patterns;

class NumericRule extends AbstractRule
{
    public function getCode(): string
    {
        return 'numeric';
    }

    public function getLabel(): string
    {
        return (string)__('Number');
    }

    public function validate(string|array $value, mixed $ruleValue, array $context): bool
    {
        return is_string($value) && preg_match(Patterns::NUMERIC, $value) === 1;
    }

    public function getDefaultMessage(mixed $ruleValue): string
    {
        return (string)__('Please enter a valid number.');
    }
}
