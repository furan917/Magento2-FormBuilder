<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation\Rule;

use Furan\FormBuilder\Model\Validation\Patterns;

class Alphanumeric extends AbstractRule
{
    public function getCode(): string
    {
        return 'alphanumeric';
    }

    public function getLabel(): string
    {
        return (string)__('Letters and numbers only');
    }

    public function validate(string|array $value, mixed $ruleValue, array $context): bool
    {
        return is_string($value) && preg_match(Patterns::ALPHANUMERIC, $value) === 1;
    }

    public function getDefaultMessage(mixed $ruleValue): string
    {
        return (string)__('Please use letters and numbers only.');
    }
}
