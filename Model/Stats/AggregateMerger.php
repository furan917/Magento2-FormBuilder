<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Stats;

/**
 * Pure and stateless: sums day-period deltas into aggregate rows. Every delta lands in four cells:
 * (day, month) x (real store/group, all-stores/all-groups rollup). Field ids are resolved by the caller.
 *
 * @phpstan-import-type Delta from DeltaCalculator
 * @phpstan-type Cell array{grain: int, period: string, store_id: int, customer_group_id: int, is_rollup: int}
 */
class AggregateMerger
{
    public const GRAIN_DAY = 0;
    public const GRAIN_MONTH = 1;

    /**
     * @param list<Delta> $deltas DeltaCalculator::compute() output, any number of submissions concatenated
     * @return array{day: list<array<string, mixed>>, value: list<array<string, mixed>>, number: list<array<string, mixed>>, label: list<array<string, mixed>>}
     */
    public function merge(array $deltas): array
    {
        $day = $value = $number = $label = [];
        foreach ($deltas as $delta) {
            foreach ($this->cells($delta) as $cell) {
                if ($delta['k'] === DeltaCalculator::SUBMISSIONS_KEY) {
                    $this->add($day, $cell, ['submissions' => $delta['h']]);
                } elseif ($delta['v'] !== null) {
                    $cell = ['field_key' => $delta['k']] + $cell + ['value_key' => $delta['v']];
                    $this->add($value, $cell, ['hits' => $delta['h']]);
                } else {
                    $cell = ['field_key' => $delta['k']] + $cell;
                    $this->add($number, $cell, ['n' => $delta['n'], 'total' => (float)$delta['t']]);
                }
            }
            if ($delta['v'] !== null && $delta['l'] !== null) {
                $label[$delta['k'] . '|' . $delta['v']] = [
                    'field_key' => $delta['k'],
                    'value_key' => $delta['v'],
                    'value' => $delta['l'],
                ];
            }
        }
        return [
            'day' => array_values($day),
            'value' => array_values($value),
            'number' => array_values($number),
            'label' => array_values($label),
        ];
    }

    /**
     * @param Delta $delta
     * @return list<Cell>
     */
    private function cells(array $delta): array
    {
        $day = (string)$delta['p'];
        $periods = [self::GRAIN_DAY => $day, self::GRAIN_MONTH => substr($day, 0, 7) . '-01'];
        $cells = [];
        foreach ([0, 1] as $isRollup) {
            foreach ($periods as $grain => $period) {
                $cells[] = [
                    'grain' => $grain,
                    'period' => $period,
                    'store_id' => $isRollup ? 0 : (int)$delta['s'],
                    'customer_group_id' => $isRollup ? 0 : (int)$delta['g'],
                    'is_rollup' => $isRollup,
                ];
            }
        }
        return $cells;
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     * @param array<string, mixed> $cell
     * @param array<string, int|float> $measures
     */
    private function add(array &$rows, array $cell, array $measures): void
    {
        $key = implode('|', $cell);
        $rows[$key] ??= $cell + array_map(static fn ($m) => $m * 0, $measures);
        foreach ($measures as $name => $amount) {
            $rows[$key][$name] += $amount;
        }
    }
}
