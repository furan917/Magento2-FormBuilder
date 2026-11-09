<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition\Operator;

class IsEmpty extends AbstractOperator
{
    public function getCode(): string
    {
        return 'is_empty';
    }

    public function getLabel(): string
    {
        return (string)__('is empty');
    }

    public function needsValue(): bool
    {
        return false;
    }

    protected function test(array $items, string $expected, bool $isArray): bool
    {
        return !$this->hasValue($items);
    }
}
