<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition\Operator;

class NotEmpty extends AbstractOperator
{
    public function getCode(): string
    {
        return 'not_empty';
    }

    public function getLabel(): string
    {
        return (string)__('is not empty');
    }

    public function needsValue(): bool
    {
        return false;
    }

    protected function test(array $items, string $expected, bool $isArray): bool
    {
        return $this->hasValue($items);
    }
}
