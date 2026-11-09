<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Stats;

use Furan\FormBuilder\Api\FieldType\StatsLabelInterface;
use Furan\FormBuilder\Model\FieldType\Pool;

/** Pure: shapes one series' aggregate rows into the chart structure the analytics page renders. */
class ChartShape
{
    public function __construct(private readonly Pool $fieldTypes, private readonly Histogram $histogram)
    {
    }

    /**
     * @param string $key field_key
     * @param int $kind frozen SeriesSpec kind
     * @param array<string, mixed>|null $field current schema field, null when the key no longer exists
     * @param array<string, int> $hits lowercase hex value_key => summed hits (marker rows included)
     * @param array{n: int, total: float}|null $number summed answers and total
     * @param array<string, string> $labels lowercase hex value_key => stored label
     * @param int|float|null $step frozen SeriesSpec bucket_step
     * @return array<string, mixed>
     */
    public function build(
        string $key,
        int $kind,
        ?array $field,
        array $hits,
        ?array $number,
        array $labels,
        int|float|null $step = null
    ): array {
        $chart = ['key' => $key, 'label' => (string)($field['label'] ?? $key)];
        $answered = $hits[$this->hex(DeltaCalculator::ANSWERED)] ?? 0;
        unset($hits[$this->hex(DeltaCalculator::ANSWERED)]);
        $hits = array_filter($hits, static fn (int $h): bool => $h > 0);

        return match ($kind) {
            SeriesSpec::KIND_CHOICE, SeriesSpec::KIND_ADDRESS => $chart + ['type' => 'choice']
                + $this->options($hits, $labels, $field, array_sum($hits)),
            SeriesSpec::KIND_MULTI => $chart + ['type' => 'choice', 'answered' => $answered]
                + $this->options($hits, $labels, $field, $answered),
            SeriesSpec::KIND_MATRIX => $chart + ['type' => 'matrix', 'answered' => $answered]
                + ['rows' => $this->matrixRows($hits, $labels, $field, $answered)],
            SeriesSpec::KIND_DISCRETE => $chart + ['type' => 'rating']
                + $this->distribution($hits, $labels, $number),
            SeriesSpec::KIND_NUMBER, SeriesSpec::KIND_DATE, SeriesSpec::KIND_DATE_RANGE => $chart
                + ['type' => 'histogram'] + $this->histogram($kind, $step, $hits, $labels, $number),
            default => $chart + ['type' => 'answered', 'answered' => $answered],
        };
    }

    public function hex(string $canonical): string
    {
        // phpcs:ignore Magento2.Security.InsecureFunction -- md5 as a non-cryptographic bucket key
        return md5($canonical);
    }

    /**
     * @param array<string, int> $hits
     * @param array<string, string> $labels
     * @param array<string, mixed>|null $field
     * @return array{options: list<array{label: string, hits: int, percent: float}>}
     */
    private function options(array $hits, array $labels, ?array $field, int $denominator): array
    {
        $schemaLabels = [];
        foreach ((array)($field['options'] ?? []) as $option) {
            $schemaLabels[$this->hex((string)($option['value'] ?? ''))] = (string)($option['label'] ?? '');
        }
        $ordered = [];
        foreach ($schemaLabels as $hex => $label) {
            if (isset($hits[$hex])) {
                $ordered[$hex] = $hits[$hex];
            }
        }
        $rest = array_diff_key($hits, $ordered);
        arsort($rest);
        $options = [];
        $labeller = $this->labeller($field);
        $named = [];
        foreach ($ordered + $rest as $hex => $count) {
            $label = $schemaLabels[$hex] ?? $labels[$hex] ?? $hex;
            $options[] = [
                'label' => $labeller === null ? $label : ($named[$label] ??= $labeller->statsLabel($label, (array)$field)),
                'hits' => $count,
                'percent' => $this->percent($count, $denominator),
            ];
        }
        return ['options' => $options];
    }

    /** @param array<string, mixed>|null $field */
    private function labeller(?array $field): ?StatsLabelInterface
    {
        $code = (string)($field['type'] ?? '');
        $type = $this->fieldTypes->has($code) ? $this->fieldTypes->get($code) : null;
        return $type instanceof StatsLabelInterface ? $type : null;
    }

    /**
     * @param array<string, int> $hits
     * @param array<string, string> $labels
     * @param array<string, mixed>|null $field
     * @return list<array{label: string, options: list<array{label: string, hits: int, percent: float}>}>
     */
    private function matrixRows(array $hits, array $labels, ?array $field, int $answered): array
    {
        $schemaRows = array_values(array_map('strval', (array)($field['settings']['rows'] ?? [])));
        $columns = array_flip(array_map(
            static fn ($option): string => (string)(is_array($option) ? ($option['label'] ?? '') : ''),
            array_values((array)($field['options'] ?? []))
        ));
        // Longest first, so a row whose name prefixes another row's name cannot claim its answers.
        $byLength = $schemaRows;
        usort($byLength, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $other = (string)__('Other (renamed)');
        $grouped = array_fill_keys($schemaRows, []);
        foreach ($hits as $hex => $count) {
            $text = $labels[$hex] ?? $hex;
            $row = $other;
            foreach ($byLength as $candidate) {
                if (str_starts_with($text, $candidate . ': ')) {
                    $row = $candidate;
                    $text = substr($text, strlen($candidate) + 2);
                    break;
                }
            }
            $grouped[$row][] = ['label' => $text, 'hits' => $count, 'percent' => $this->percent($count, $answered)];
        }
        // Schema columns in order, then answers no longer offered by most hits.
        $rank = static fn (array $item): array => isset($columns[$item['label']])
            ? [0, $columns[$item['label']], 0]
            : [1, 0, -$item['hits']];
        foreach ($grouped as &$items) {
            usort($items, static fn (array $a, array $b): int => $rank($a) <=> $rank($b));
        }
        unset($items);

        // A list, not a map: numeric row names would become integer keys and lose their order.
        $rows = [];
        foreach ($grouped as $row => $items) {
            if ($items !== []) {
                $rows[] = ['label' => (string)$row, 'options' => $items];
            }
        }
        return $rows;
    }

    /**
     * @param array<string, int> $hits
     * @param array<string, string> $labels
     * @param array{n: int, total: float}|null $number
     * @return array{average: ?float, n: int, options: list<array{label: string, hits: int, percent: float}>}
     */
    private function distribution(array $hits, array $labels, ?array $number): array
    {
        $options = [];
        $total = array_sum($hits);
        foreach ($this->sorted($hits, $labels) as $hex => $count) {
            $options[] = [
                'label' => $labels[$hex] ?? $hex,
                'hits' => $count,
                'percent' => $this->percent($count, $total),
            ];
        }
        $n = (int)($number['n'] ?? 0);
        return ['average' => $this->average($number), 'n' => $n, 'options' => $options];
    }

    /**
     * Hits without a stored label cannot be placed and are left out.
     *
     * @param array<string, int> $hits
     * @param array<string, string> $labels
     * @param array{n: int, total: float}|null $number
     * @return array{average: ?float, buckets: list<array{label: string, hits: int}>}
     */
    private function histogram(int $kind, int|float|null $step, array $hits, array $labels, ?array $number): array
    {
        $byLabel = [];
        foreach ($hits as $hex => $count) {
            if (isset($labels[$hex])) {
                $byLabel[$labels[$hex]] = ($byLabel[$labels[$hex]] ?? 0) + $count;
            }
        }
        return ['average' => $this->average($number), 'buckets' => $this->histogram->bins($kind, $step, $byLabel)];
    }

    /**
     * Numeric labels sort as numbers, others as text.
     *
     * @param array<string, int> $hits
     * @param array<string, string> $labels
     * @return array<string, int>
     */
    private function sorted(array $hits, array $labels): array
    {
        uksort($hits, function (string $a, string $b) use ($labels): int {
            $left = $labels[$a] ?? $a;
            $right = $labels[$b] ?? $b;
            return is_numeric($left) && is_numeric($right) ? (float)$left <=> (float)$right : strcmp($left, $right);
        });
        return $hits;
    }

    /** @param array{n: int, total: float}|null $number */
    private function average(?array $number): ?float
    {
        if ($number === null || $number['n'] <= 0) {
            return null;
        }

        return (float)$number['total'] / $number['n'];
    }

    private function percent(int $count, int $denominator): float
    {
        return $denominator > 0 ? round($count / $denominator * 100, 1) : 0.0;
    }
}
