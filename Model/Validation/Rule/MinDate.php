<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation\Rule;

class MinDate extends AbstractDateRule
{
    public function getCode(): string
    {
        return 'min_date';
    }

    public function getLabel(): string
    {
        return (string)__('Earliest date');
    }

    public function getValueType(): string
    {
        return 'date';
    }

    public function validate(string|array $value, mixed $ruleValue, array $context): bool
    {
        return $this->datePart($value) !== null && $this->datePart($value) >= (string)$ruleValue;
    }

    public function getDefaultMessage(mixed $ruleValue): string
    {
        return (string)__('Please choose a date on or after %1.', (string)$ruleValue);
    }
}
