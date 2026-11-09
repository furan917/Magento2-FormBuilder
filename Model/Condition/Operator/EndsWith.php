<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition\Operator;

class EndsWith extends AbstractOperator
{
    public function getCode(): string
    {
        return 'ends_with';
    }

    public function getLabel(): string
    {
        return (string)__('ends with');
    }

    protected function test(array $items, string $expected, bool $isArray): bool
    {
        return $this->anyOf($items, static fn (string $v): bool => str_ends_with($v, $expected));
    }
}
