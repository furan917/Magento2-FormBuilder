<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition\Operator;

class GreaterThan extends AbstractOperator
{
    public function getCode(): string
    {
        return 'greater_than';
    }

    public function getLabel(): string
    {
        return (string)__('is greater than');
    }

    protected function test(array $items, string $expected, bool $isArray): bool
    {
        return $this->anyOf($items, fn (string $v): bool => $this->ordered($v, $expected, '>'));
    }
}
