<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Api\ConditionOperatorInterface;

/** Custom operator that records its arguments and only applies to the listed types. */
class ArrayAwareOperator implements ConditionOperatorInterface
{
    public array $calls = [];

    public function __construct(private readonly string $code = 'sounds_like', private readonly array $types = [])
    {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return 'sounds like';
    }

    public function appliesTo(string $typeCode): bool
    {
        return $this->types === [] || in_array($typeCode, $this->types, true);
    }

    public function needsValue(): bool
    {
        return true;
    }

    public function evaluate(string|array $actual, string $expected, array $field): bool
    {
        $this->calls[] = [$actual, $expected, $field];
        return $expected === 'yes';
    }
}
