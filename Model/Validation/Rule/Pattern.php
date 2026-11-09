<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation\Rule;

use Furan\FormBuilder\Model\Validation\PatternMatcher;

class Pattern extends AbstractRule
{
    public function __construct(private readonly PatternMatcher $matcher)
    {
    }

    public function getCode(): string
    {
        return 'pattern';
    }

    public function getLabel(): string
    {
        return (string)__('Pattern');
    }

    public function getValueType(): string
    {
        return 'regex';
    }

    public function validate(string|array $value, mixed $ruleValue, array $context): bool
    {
        return is_string($value) && is_string($ruleValue) && $this->matcher->matches($ruleValue, $value);
    }

    public function getDefaultMessage(mixed $ruleValue): string
    {
        return (string)__('Please match the requested format.');
    }
}
