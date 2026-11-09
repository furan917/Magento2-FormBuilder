<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition\Operator;

class Equals extends AbstractOperator
{
    public function getCode(): string
    {
        return 'equals';
    }

    public function getLabel(): string
    {
        return (string)__('equals');
    }

    protected function test(array $items, string $expected, bool $isArray): bool
    {
        return $this->anyOf($items, static fn (string $v): bool => $v === $expected);
    }
}
