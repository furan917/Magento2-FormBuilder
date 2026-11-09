<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition\Operator;

class Contains extends AbstractOperator
{
    public function getCode(): string
    {
        return 'contains';
    }

    public function getLabel(): string
    {
        return (string)__('contains');
    }

    protected function test(array $items, string $expected, bool $isArray): bool
    {
        return $this->anyOf(
            $items,
            static fn (string $v): bool => $isArray ? $v === $expected : str_contains($v, $expected)
        );
    }
}
