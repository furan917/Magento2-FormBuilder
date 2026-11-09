<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation\Rule;

class Max extends AbstractNumericRule
{
    public function getCode(): string
    {
        return 'max';
    }

    public function getLabel(): string
    {
        return (string)__('Maximum value');
    }

    public function getValueType(): string
    {
        return 'number';
    }

    public function validate(string|array $value, mixed $ruleValue, array $context): bool
    {
        return $this->isNumeric($value) && (float)$value <= (float)$ruleValue;
    }

    public function getDefaultMessage(mixed $ruleValue): string
    {
        return (string)__('Please enter a value of no more than %1.', (float)$ruleValue);
    }
}
