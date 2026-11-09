<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition\Operator;

class IsOneOf extends AbstractOperator
{
    public function getCode(): string
    {
        return 'is_one_of';
    }

    public function getLabel(): string
    {
        return (string)__('is one of');
    }

    protected function test(array $items, string $expected, bool $isArray): bool
    {
        return $this->anyInList($items, $expected);
    }
}
