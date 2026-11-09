<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation\Rule;

use Furan\FormBuilder\Model\Validation\Patterns;

class Integer extends AbstractRule
{
    public function getCode(): string
    {
        return 'integer';
    }

    public function getLabel(): string
    {
        return (string)__('Whole number');
    }

    public function validate(string|array $value, mixed $ruleValue, array $context): bool
    {
        return is_string($value) && preg_match(Patterns::INTEGER, $value) === 1;
    }

    public function getDefaultMessage(mixed $ruleValue): string
    {
        return (string)__('Please enter a whole number.');
    }
}
