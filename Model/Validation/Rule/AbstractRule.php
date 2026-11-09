<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation\Rule;

use Furan\FormBuilder\Api\ValidationRuleInterface;

abstract class AbstractRule implements ValidationRuleInterface
{
    public function getValueType(): string
    {
        return 'none';
    }

    public function getDefaultMessage(mixed $ruleValue): string
    {
        return '';
    }

    protected function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }
}
