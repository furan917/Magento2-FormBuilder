<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition\Operator;

use Furan\FormBuilder\Api\ConditionOperatorInterface;

/**
 * Shared trim + case-insensitive, any-element behaviour of the built-in operators.
 */
abstract class AbstractOperator implements ConditionOperatorInterface
{
    private const NUMBER = '/^-?\d+(\.\d+)?$/D';
    private const DATE = '/^\d{4}-\d{2}-\d{2}$/D';

    public function appliesTo(string $typeCode): bool
    {
        return true;
    }

    public function needsValue(): bool
    {
        return true;
    }

    public function evaluate(string|array $actual, string $expected, array $field): bool
    {
        return $this->test($this->items($actual), $this->normalize($expected), is_array($actual));
    }

    /**
     * @param string[] $items normalised answer elements
     */
    abstract protected function test(array $items, string $expected, bool $isArray): bool;

    /**
     * @param string|array<int|string, mixed> $actual
     * @return string[]
     */
    protected function items(string|array $actual): array
    {
        return array_map(
            $this->normalize(...),
            is_array($actual) ? array_values($actual) : [$actual]
        );
    }

    protected function normalize(mixed $value): string
    {
        return mb_strtolower(trim(is_scalar($value) ? (string)$value : ''), 'UTF-8');
    }

    /**
     * @param string[] $items
     */
    protected function anyOf(array $items, callable $test): bool
    {
        foreach ($items as $item) {
            if ($test($item)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether any answer element is an entry of the newline-separated list (entries trimmed, blanks ignored).
     *
     * @param string[] $items
     */
    protected function anyInList(array $items, string $list): bool
    {
        $entries = array_filter(array_map(trim(...), explode("\n", $list)), static fn (string $e): bool => $e !== '');
        return $this->anyOf($items, static fn (string $v): bool => in_array($v, $entries, true));
    }

    /**
     * @param string[] $items
     */
    protected function hasValue(array $items): bool
    {
        return $this->anyOf($items, static fn (string $v): bool => $v !== '');
    }

    /** Numeric compare, else ISO date compare, else false. */
    protected function ordered(string $a, string $b, string $operator): bool
    {
        if (preg_match(self::NUMBER, $a) === 1 && preg_match(self::NUMBER, $b) === 1) {
            $order = (float)$a <=> (float)$b;
        } elseif (preg_match(self::DATE, $a) === 1 && preg_match(self::DATE, $b) === 1) {
            $order = strcmp($a, $b) <=> 0;
        } else {
            return false;
        }
        return match ($operator) {
            '>' => $order > 0,
            '<' => $order < 0,
            '>=' => $order >= 0,
            default => $order <= 0,
        };
    }
}
