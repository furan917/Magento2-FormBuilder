<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation\Rule;

class MinSelected extends AbstractSelectionRule
{
    public function getCode(): string
    {
        return 'min_selected';
    }

    public function getLabel(): string
    {
        return (string)__('Minimum selected');
    }

    public function getValueType(): string
    {
        return 'int';
    }

    public function validate(string|array $value, mixed $ruleValue, array $context): bool
    {
        return $this->selectedCount($value) >= (int)$ruleValue;
    }

    public function getDefaultMessage(mixed $ruleValue): string
    {
        return (string)__('Please choose at least %1 options.', (int)$ruleValue);
    }
}
