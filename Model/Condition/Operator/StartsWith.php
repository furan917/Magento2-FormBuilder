<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition\Operator;

class StartsWith extends AbstractOperator
{
    public function getCode(): string
    {
        return 'starts_with';
    }

    public function getLabel(): string
    {
        return (string)__('starts with');
    }

    protected function test(array $items, string $expected, bool $isArray): bool
    {
        return $this->anyOf($items, static fn (string $v): bool => str_starts_with($v, $expected));
    }
}
