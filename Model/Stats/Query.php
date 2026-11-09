<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Stats;

use DateTimeImmutable;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Chart data for one form, read only from the stat_* aggregate tables (callers fold the journal first).
 * One aggregating query per table; period already holds local dates, so filter dates are compared as given.
 *
 * Index use:
 * - stat_value / stat_number: `field_id IN (...)` plus per-segment `grain = ? AND is_rollup = ? AND period BETWEEN`
 *   is a multi-range scan on the PRIMARY prefix (field_id, grain, is_rollup, period).
 * - stat_day: PRIMARY prefix (form_id, grain, is_rollup, period).
 * - Unfiltered reads use the is_rollup = 1 rows (rows examined ~ periods x values); any store or group filter
 *   reads is_rollup = 0 rows and applies store_id / customer_group_id as row filters after the period range
 *   (bounded by periods x stores x groups x values). Rollup and detail rows are never summed together.
 * - Month rows serve whole months (1st through min(last day, today)), day rows only the partial months.
 *
 * @phpstan-type Scope array{rollup: int, store_id: ?int, customer_group_id: ?int}
 * @phpstan-type Segments list<array{int, string, string}>
 */
class Query
{
    private const MAX_DAY_POINTS = 92;
    private const DEFAULT_MONTHS = 12;
    private const MAX_YEARS = 5;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly TimezoneInterface $timezone,
        private readonly ChartShape $shape
    ) {
    }

    /**
     * @param list<array<string, mixed>> $fields current schema field items
     * @param array{store_id?: ?int, customer_group_id?: ?int, from?: ?string, to?: ?string} $filters
     * @return array{from: string, to: string, granularity: string, submissions: int, time: list<array{period: string, submissions: int}>, fields: list<array<string, mixed>>, removed: list<array<string, mixed>>}
     */
    public function charts(int $formId, array $fields, array $filters = []): array
    {
        $today = $this->timezone->date()->format('Y-m-d');
        [$from, $to] = $this->range($filters['from'] ?? null, $filters['to'] ?? null, $today);
        $scope = $this->scope($filters);
        $segments = $this->segments($from, $to, $today);
        $dayChart = $this->days($from, $to) <= self::MAX_DAY_POINTS;

        $series = $this->series($formId);
        $byId = [];
        foreach ($series as $row) {
            $byId[$row['field_id']] = $row;
        }
        $ids = array_keys($byId);
        $hits = $ids === [] ? [] : $this->valueRows($ids, $segments, $scope);
        $numbers = $this->numberRows($byId, $segments, $scope);
        $labels = $this->labels($hits);
        $time = $this->timeRows(
            $formId,
            $dayChart ? [[0, $from, $to]] : $segments,
            $scope,
            $dayChart
        );

        $schemaFields = [];
        foreach ($fields as $field) {
            $schemaFields[(string)($field['name'] ?? '')] = $field;
        }
        $charts = ['fields' => [], 'removed' => []];
        foreach ($byId as $id => $row) {
            if ($row['kind'] === SeriesSpec::KIND_NONE) {
                continue;
            }
            $field = $schemaFields[$row['field_key']] ?? null;
            $charts[$field === null ? 'removed' : 'fields'][] = $this->shape->build(
                $row['field_key'],
                $row['kind'],
                $field,
                $hits[$id] ?? [],
                $numbers[$id] ?? null,
                $labels[$id] ?? [],
                $row['bucket_step']
            );
        }

        return [
            'from' => $from,
            'to' => $to,
            'granularity' => $dayChart ? 'day' : 'month',
            'submissions' => array_sum(array_column($time, 'submissions')),
            'time' => $time,
        ] + $charts;
    }

    /** @return array{string, string} valid, ordered and at most MAX_YEARS long */
    private function range(mixed $from, mixed $to, string $today): array
    {
        $to = $this->validDate($to) ?? $today;
        $from = $this->validDate($from) ?? (new DateTimeImmutable($today))
            ->modify('first day of this month')
            ->modify('-' . (self::DEFAULT_MONTHS - 1) . ' months')
            ->format('Y-m-d');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $earliest = (new DateTimeImmutable($to))->modify('-' . self::MAX_YEARS . ' years +1 day')->format('Y-m-d');

        return [max($from, $earliest), $to];
    }

    private function validDate(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1
            || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])
        ) {
            return null;
        }
        return $value;
    }

    /** @return array<int, array{field_id: int, field_key: string, kind: int, bucket_step: ?float}> */
    private function series(int $formId): array
    {
        $rows = $this->connection()->fetchAll(
            'SELECT field_id, field_key, kind, bucket_step FROM ' . $this->table('stat_field') . ' WHERE form_id = ?',
            [$formId]
        );
        return array_map(static fn (array $r): array => [
            'field_id' => (int)$r['field_id'],
            'field_key' => (string)$r['field_key'],
            'kind' => (int)$r['kind'],
            'bucket_step' => $r['bucket_step'] === null ? null : (float)$r['bucket_step'],
        ], $rows);
    }

    /**
     * @param list<int> $ids
     * @param Segments $segments
     * @param Scope $scope
     * @return array<int, array<string, int>> field_id => hex value_key => hits
     */
    private function valueRows(array $ids, array $segments, array $scope): array
    {
        [$where, $bind] = $this->where($segments, $scope);
        $rows = $this->connection()->fetchAll(
            'SELECT field_id, HEX(value_key) AS value_key, SUM(hits) AS hits FROM ' . $this->table('stat_value')
            . ' WHERE field_id IN (' . $this->marks($ids) . ') AND ' . $where
            . ' GROUP BY field_id, value_key',
            array_merge($ids, $bind)
        );
        $hits = [];
        foreach ($rows as $row) {
            $hits[(int)$row['field_id']][strtolower((string)$row['value_key'])] = (int)$row['hits'];
        }
        return $hits;
    }

    /**
     * @param array<int, array{field_id: int, field_key: string, kind: int, bucket_step: ?float}> $byId
     * @param Segments $segments
     * @param Scope $scope
     * @return array<int, array{n: int, total: float}>
     */
    private function numberRows(array $byId, array $segments, array $scope): array
    {
        $ids = [];
        foreach ($byId as $id => $row) {
            if (in_array($row['kind'], [SeriesSpec::KIND_DISCRETE, SeriesSpec::KIND_NUMBER, SeriesSpec::KIND_DATE_RANGE], true)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        [$where, $bind] = $this->where($segments, $scope);
        $rows = $this->connection()->fetchAll(
            'SELECT field_id, SUM(n) AS n, SUM(total) AS total FROM ' . $this->table('stat_number')
            . ' WHERE field_id IN (' . $this->marks($ids) . ') AND ' . $where . ' GROUP BY field_id',
            array_merge($ids, $bind)
        );
        $numbers = [];
        foreach ($rows as $row) {
            $numbers[(int)$row['field_id']] = ['n' => (int)$row['n'], 'total' => (float)$row['total']];
        }
        return $numbers;
    }

    /**
     * @param array<int, array<string, int>> $hits
     * @return array<int, array<string, string>> field_id => hex value_key => label
     */
    private function labels(array $hits): array
    {
        $conditions = [];
        $bind = [];
        foreach ($hits as $id => $values) {
            $keys = array_keys(array_filter($values, static fn (int $h): bool => $h > 0));
            if ($keys !== []) {
                $conditions[] = '(field_id = ? AND value_key IN (' . implode(',', array_fill(0, count($keys), 'UNHEX(?)')) . '))';
                array_push($bind, $id, ...$keys);
            }
        }
        if ($conditions === []) {
            return [];
        }
        $rows = $this->connection()->fetchAll(
            'SELECT field_id, HEX(value_key) AS value_key, label FROM ' . $this->table('stat_label')
            . ' WHERE ' . implode(' OR ', $conditions),
            $bind
        );
        $labels = [];
        foreach ($rows as $row) {
            $labels[(int)$row['field_id']][strtolower((string)$row['value_key'])] = (string)$row['label'];
        }
        return $labels;
    }

    /**
     * @param Segments $segments
     * @param Scope $scope
     * @return list<array{period: string, submissions: int}>
     */
    private function timeRows(int $formId, array $segments, array $scope, bool $dayChart): array
    {
        [$where, $bind] = $this->where($segments, $scope);
        $rows = $this->connection()->fetchAll(
            'SELECT period, SUM(submissions) AS submissions FROM ' . $this->table('stat_day')
            . ' WHERE form_id = ? AND ' . $where . ' GROUP BY period',
            array_merge([$formId], $bind)
        );
        $points = $this->emptyPeriods($segments, $dayChart);
        foreach ($rows as $row) {
            $period = $dayChart ? (string)$row['period'] : substr((string)$row['period'], 0, 7);
            $points[$period] = ($points[$period] ?? 0) + (int)$row['submissions'];
        }
        ksort($points);
        $time = [];
        foreach ($points as $period => $submissions) {
            $time[] = ['period' => (string)$period, 'submissions' => $submissions];
        }
        return $time;
    }

    /**
     * @param Segments $segments
     * @return array<string, int> every day (or month) between the first and last segment bound, zeroed
     */
    private function emptyPeriods(array $segments, bool $dayChart): array
    {
        $from = new DateTimeImmutable($segments[0][1]);
        $to = new DateTimeImmutable($segments[count($segments) - 1][2]);
        $format = $dayChart ? 'Y-m-d' : 'Y-m';
        $step = $dayChart ? '+1 day' : '+1 month';
        $cursor = $dayChart ? $from : $from->modify('first day of this month');
        $periods = [];
        while ($cursor <= $to) {
            $periods[$cursor->format($format)] = 0;
            $cursor = $cursor->modify($step);
        }
        return $periods;
    }

    /**
     * @param array{store_id?: ?int, customer_group_id?: ?int, from?: ?string, to?: ?string} $filters
     * @return Scope
     */
    private function scope(array $filters): array
    {
        $store = isset($filters['store_id']) ? (int)$filters['store_id'] : null;
        $group = isset($filters['customer_group_id']) ? (int)$filters['customer_group_id'] : null;
        return [
            'rollup' => $store === null && $group === null ? 1 : 0,
            'store_id' => $store,
            'customer_group_id' => $group,
        ];
    }

    /**
     * @param Segments $segments
     * @param Scope $scope
     * @return array{string, list<int|string>} SQL condition and its bind values
     */
    private function where(array $segments, array $scope): array
    {
        $parts = [];
        $bind = [];
        foreach ($segments as [$grain, $start, $end]) {
            $parts[] = '(grain = ? AND is_rollup = ? AND period BETWEEN ? AND ?)';
            array_push($bind, $grain, $scope['rollup'], $start, $end);
        }
        $sql = '(' . ($parts === [] ? '0 = 1' : implode(' OR ', $parts)) . ')';
        foreach (['store_id', 'customer_group_id'] as $column) {
            if ($scope[$column] !== null) {
                $sql .= ' AND ' . $column . ' = ?';
                $bind[] = $scope[$column];
            }
        }
        return [$sql, $bind];
    }

    /** @return Segments [grain, first period, last period], chronological */
    private function segments(string $from, string $to, string $today): array
    {
        /** @var Segments $segments */
        $segments = [];
        $month = (new DateTimeImmutable($from))->modify('first day of this month');
        $lastMonth = (new DateTimeImmutable($to))->modify('first day of this month')->format('Y-m-d');
        while ($month->format('Y-m-d') <= $lastMonth) {
            $current = $month;
            $month = $month->modify('+1 month');
            $first = $current->format('Y-m-d');
            $whole = $from <= $first && $to >= min($current->format('Y-m-t'), $today);
            $grain = $whole ? 1 : 0;
            $start = $whole ? $first : max($from, $first);
            $end = $whole ? $first : min($to, $current->format('Y-m-t'));
            $last = count($segments) - 1;
            if ($last >= 0 && $segments[$last][0] === $grain && ($whole || $this->nextDay($segments[$last][2]) === $start)) {
                $segments[$last] = [$grain, $segments[$last][1], $end];
                continue;
            }
            $segments[] = [$grain, $start, $end];
        }
        return $segments;
    }

    private function nextDay(string $date): string
    {
        return (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    }

    private function days(string $from, string $to): int
    {
        return (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
    }

    /** @param array<int|string, mixed> $values */
    private function marks(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName('furan_formbuilder_' . $name);
    }

    private function connection(): \Magento\Framework\DB\Adapter\AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
