<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition\Operator;

class LessOrEqual extends AbstractOperator
{
    public function getCode(): string
    {
        return 'less_or_equal';
    }

    public function getLabel(): string
    {
        return (string)__('is less than or equal to');
    }

    protected function test(array $items, string $expected, bool $isArray): bool
    {
        return $this->anyOf($items, fn (string $v): bool => $this->ordered($v, $expected, '<='));
    }
}
