<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Stats;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Pure: turns one submission into day-period aggregate deltas using only the frozen series specs it is given,
 * so a removal always hits exactly the buckets its addition hit. Month rows are derived by the folder, never here.
 *
 * @phpstan-type Delta array{k: string, v: ?string, l: ?string, p: string, s: int, g: int, h: int, n: int, t: float}
 * @phpstan-type Dims array{store_id: int, customer_group_id: int, created_at: string, timezone: string}
 * @phpstan-type Spec array{kind: int, bucket_step?: int|float|string|null}
 * @phpstan-type Entry array{v: ?string, h: int, n?: int, t?: float}
 */
class DeltaCalculator
{
    public const SUBMISSIONS_KEY = '__submissions';
    public const ANSWERED = '__answered';

    private const LABEL_LIMIT = 255;

    public function __construct(private readonly Bucket $bucket)
    {
    }

    /**
     * @param array<int|string, mixed> $payload list of ['name' => string, 'value' => mixed, ...]
     * @param Dims $dims store_id (0 for none), customer_group_id, created_at (UTC Y-m-d H:i:s), timezone
     * @param array<string, Spec> $specs field_key => frozen series spec
     * @param int $sign 1 = addition, -1 = removal
     * @return list<Delta>
     */
    public function compute(array $payload, array $dims, array $specs, int $sign): array
    {
        $base = [
            'p' => $this->day((string)$dims['created_at'], (string)$dims['timezone']),
            's' => (int)$dims['store_id'],
            'g' => (int)$dims['customer_group_id'],
        ];
        $deltas = [];
        foreach ($payload as $item) {
            $key = is_array($item) ? ($item['name'] ?? null) : null;
            if (!is_string($key) || !isset($specs[$key])) {
                continue;
            }
            $spec = $specs[$key];
            $value = $item['value'] ?? null;
            foreach ($this->entries((int)$spec['kind'], $spec['bucket_step'] ?? null, $value) as $entry) {
                $deltas[] = $this->delta($key, $entry, $base, $sign);
            }
        }
        $deltas[] = $this->delta(self::SUBMISSIONS_KEY, ['v' => null, 'h' => 1], $base, $sign);
        return $deltas;
    }

    /** @return list<Entry> v = canonical value (null = average only) */
    private function entries(int $kind, int|float|string|null $step, mixed $value): array
    {
        return match ($kind) {
            SeriesSpec::KIND_CHOICE => $this->counted($this->scalar($value)),
            SeriesSpec::KIND_MULTI, SeriesSpec::KIND_MATRIX => $this->multi($value),
            SeriesSpec::KIND_DISCRETE => $this->discrete($value),
            SeriesSpec::KIND_NUMBER => $this->number($step, $value),
            SeriesSpec::KIND_DATE => $this->counted($this->date($value, $step)),
            SeriesSpec::KIND_DATE_RANGE => $this->dateRange($value, $step),
            SeriesSpec::KIND_ADDRESS => $this->counted(
                is_array($value) ? $this->scalar($value['country_id'] ?? null) : null
            ),
            SeriesSpec::KIND_ANSWERED_ONLY => $this->answeredOnly($value),
            default => [],
        };
    }

    /** @return list<Entry> */
    private function multi(mixed $value): array
    {
        $entries = $this->counted(...$this->scalarList($value));
        return $entries === [] ? [] : array_merge($entries, $this->answeredOnly(true));
    }

    /** @return list<Entry> */
    private function counted(?string ...$values): array
    {
        $entries = [];
        foreach (array_unique(array_filter($values, static fn ($v) => $v !== null)) as $value) {
            $entries[] = ['v' => $value, 'h' => 1];
        }
        return $entries;
    }

    /** @return list<Entry> */
    private function discrete(mixed $value): array
    {
        if (!is_numeric($value)) {
            return [];
        }
        return [['v' => $this->bucket->format($value), 'h' => 1], $this->average((float)$value)];
    }

    /** @return list<Entry> */
    private function number(int|float|string|null $step, mixed $value): array
    {
        $bucket = is_numeric($value) ? $this->bucket->number($value, $step === null ? null : (float)$step) : null;
        return $bucket === null ? [] : [['v' => $bucket, 'h' => 1], $this->average((float)$value)];
    }

    private function date(mixed $value, int|float|string|null $step): ?string
    {
        return is_string($value) ? $this->bucket->date($value, (int)$step) : null;
    }

    /** @return list<Entry> */
    private function dateRange(mixed $value, int|float|string|null $step): array
    {
        if (!is_array($value)) {
            return [];
        }
        $start = $this->date($value['start'] ?? null, $step);
        if ($start === null) {
            return [];
        }
        $entries = [['v' => $start, 'h' => 1]];
        $days = $this->lengthInDays((string)$value['start'], (string)($value['end'] ?? ''));
        if ($days !== null) {
            $entries[] = $this->average((float)$days);
        }
        return $entries;
    }

    /** @return list<Entry> */
    private function answeredOnly(mixed $value): array
    {
        $answered = is_array($value) ? $value !== [] : trim((string)$value) !== '';
        return $answered ? [['v' => self::ANSWERED, 'h' => 1]] : [];
    }

    /** @return Entry */
    private function average(float $value): array
    {
        return ['v' => null, 'h' => 0, 'n' => 1, 't' => $value];
    }

    private function scalar(mixed $value): ?string
    {
        if (is_int($value) || is_float($value) || is_string($value)) {
            $text = (string)$value;
            return $text === '' ? null : $text;
        }
        return null;
    }

    /** @return array<int, ?string> */
    private function scalarList(mixed $value): array
    {
        return array_map($this->scalar(...), is_array($value) ? array_values($value) : [$value]);
    }

    private function lengthInDays(string $start, string $end): ?int
    {
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', substr($start, 0, 10), new DateTimeZone('UTC'));
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', substr($end, 0, 10), new DateTimeZone('UTC'));
        if ($from === false || $to === false || $to < $from) {
            return null;
        }
        return (int)$from->diff($to)->days + 1;
    }

    private function day(string $createdAtUtc, string $timezone): string
    {
        return (new DateTimeImmutable($createdAtUtc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($timezone))
            ->format('Y-m-d');
    }

    /**
     * @param Entry $entry
     * @param array{p: string, s: int, g: int} $base
     * @return Delta
     */
    private function delta(string $key, array $entry, array $base, int $sign): array
    {
        $canonical = $entry['v'];
        $isMarker = $canonical === self::ANSWERED;
        return [
            'k' => $key,
            'v' => $canonical === null ? null : $this->valueKey($canonical),
            'l' => ($canonical === null || $isMarker || $key === self::SUBMISSIONS_KEY)
                ? null
                : mb_substr($canonical, 0, self::LABEL_LIMIT),
        ] + $base + [
            'h' => $sign * $entry['h'],
            'n' => $sign * ($entry['n'] ?? 0),
            't' => $sign * ($entry['t'] ?? 0.0),
        ];
    }

    /** Non-cryptographic: a fixed-width bucket id for the (field_id, value_key) PK. */
    private function valueKey(string $canonical): string
    {
        // phpcs:ignore Magento2.Security.InsecureFunction -- md5 as a non-cryptographic bucket key
        return md5($canonical);
    }
}
