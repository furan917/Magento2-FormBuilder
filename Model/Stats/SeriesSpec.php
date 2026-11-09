<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Stats;

use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Model\FieldType\Pool;

/** Derives the frozen {kind, bucket_step} of a stats series from a schema field. Pure function of the field. */
class SeriesSpec
{
    public const KIND_NONE = StatsAwareTypeInterface::KIND_NONE;
    public const KIND_CHOICE = StatsAwareTypeInterface::KIND_CHOICE;
    public const KIND_MULTI = StatsAwareTypeInterface::KIND_MULTI;
    public const KIND_DISCRETE = StatsAwareTypeInterface::KIND_DISCRETE;
    public const KIND_NUMBER = StatsAwareTypeInterface::KIND_NUMBER;
    public const KIND_DATE = StatsAwareTypeInterface::KIND_DATE;
    public const KIND_DATE_RANGE = StatsAwareTypeInterface::KIND_DATE_RANGE;
    public const KIND_MATRIX = StatsAwareTypeInterface::KIND_MATRIX;
    public const KIND_ADDRESS = StatsAwareTypeInterface::KIND_ADDRESS;
    public const KIND_ANSWERED_ONLY = StatsAwareTypeInterface::KIND_ANSWERED_ONLY;

    private const MAX_NUMBER_BUCKETS = 50;
    private const MAX_DAY_SPAN = 92;

    public function __construct(private readonly Pool $types)
    {
    }

    /**
     * @param array<string, mixed> $field
     * @return array{kind: int, bucket_step: int|float|null}
     */
    public function specFor(array $field): array
    {
        $kind = $this->kindOf($field);
        $step = match ($kind) {
            self::KIND_NUMBER => $this->niceStep(
                $this->ruleValue($field, 'min') ?? $this->settingValue($field, 'min'),
                $this->ruleValue($field, 'max') ?? $this->settingValue($field, 'max')
            ),
            self::KIND_DATE, self::KIND_DATE_RANGE => $this->dateStep($field),
            default => null,
        };
        return ['kind' => $kind, 'bucket_step' => $step];
    }

    /** @param array<string, mixed> $field */
    private function kindOf(array $field): int
    {
        $type = (string)($field['type'] ?? '');
        $fieldType = $this->types->has($type) ? $this->types->get($type) : null;
        if (!$fieldType instanceof StatsAwareTypeInterface) {
            return self::KIND_ANSWERED_ONLY;
        }
        $kind = $fieldType->statsKind($field);
        return $kind !== null && $kind >= self::KIND_NONE && $kind <= self::KIND_ANSWERED_ONLY
            ? $kind
            : self::KIND_NONE;
    }

    private function niceStep(?float $min, ?float $max): ?float
    {
        if ($min === null || $max === null || $max < $min) {
            return null;
        }
        for ($exp = -6; $exp <= 15; $exp++) {
            foreach ([1, 2, 5] as $mantissa) {
                $step = (float)($mantissa . 'e' . $exp);
                $buckets = floor(round($max / $step, 9)) - floor(round($min / $step, 9)) + 1;
                if ($buckets <= self::MAX_NUMBER_BUCKETS) {
                    return $step;
                }
            }
        }
        return null;
    }

    /**
     * Days only when the rules bound the answers to a short span: unbounded dates by day would be a row per answer.
     *
     * @param array<string, mixed> $field
     */
    private function dateStep(array $field): int
    {
        $min = $this->dayIndex($this->ruleValue($field, 'min_date', true));
        $max = $this->dayIndex($this->ruleValue($field, 'max_date', true));
        if ($min !== null && $max !== null && $max >= $min && $max - $min + 1 <= self::MAX_DAY_SPAN) {
            return Bucket::DAY;
        }
        return Bucket::MONTH;
    }

    private function dayIndex(mixed $date): ?int
    {
        if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m)
            || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])
        ) {
            return null;
        }
        return (int)(gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]) / 86400);
    }

    /** @param array<string, mixed> $field */
    private function settingValue(array $field, string $name): ?float
    {
        $value = ((array)($field['settings'] ?? []))[$name] ?? null;
        return is_numeric($value) ? (float)$value : null;
    }

    /** @param array<string, mixed> $field */
    private function ruleValue(array $field, string $code, bool $raw = false): mixed
    {
        foreach ((array)($field['rules'] ?? []) as $rule) {
            if (is_array($rule) && ($rule['rule'] ?? null) === $code) {
                $value = $rule['value'] ?? null;
                if ($raw) {
                    return $value;
                }
                return is_numeric($value) ? (float)$value : null;
            }
        }
        return null;
    }
}
