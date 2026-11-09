<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition\Operator;

class NotContains extends AbstractOperator
{
    public function getCode(): string
    {
        return 'not_contains';
    }

    public function getLabel(): string
    {
        return (string)__('does not contain');
    }

    protected function test(array $items, string $expected, bool $isArray): bool
    {
        return !$this->anyOf(
            $items,
            static fn (string $v): bool => $isArray ? $v === $expected : str_contains($v, $expected)
        );
    }
}
