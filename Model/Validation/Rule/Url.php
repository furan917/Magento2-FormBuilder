<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation\Rule;

use Furan\FormBuilder\Model\Validation\Patterns;

class Url extends AbstractRule
{
    public function getCode(): string
    {
        return 'url';
    }

    public function getLabel(): string
    {
        return (string)__('URL');
    }

    public function validate(string|array $value, mixed $ruleValue, array $context): bool
    {
        return is_string($value) && preg_match(Patterns::URL, $value) === 1;
    }

    public function getDefaultMessage(mixed $ruleValue): string
    {
        return (string)__('Please enter a valid URL starting with http:// or https://.');
    }
}
