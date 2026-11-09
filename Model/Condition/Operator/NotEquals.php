<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition\Operator;

class NotEquals extends AbstractOperator
{
    public function getCode(): string
    {
        return 'not_equals';
    }

    public function getLabel(): string
    {
        return (string)__('does not equal');
    }

    protected function test(array $items, string $expected, bool $isArray): bool
    {
        return !$this->anyOf($items, static fn (string $v): bool => $v === $expected);
    }
}
