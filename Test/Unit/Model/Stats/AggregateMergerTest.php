<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Stats;

use Furan\FormBuilder\Model\Stats\AggregateMerger;
use PHPUnit\Framework\TestCase;

class AggregateMergerTest extends TestCase
{
    public function testItWritesBothDayAndMonthRows(): void
    {
        $merged = (new AggregateMerger())->merge([
            $this->delta('__submissions', null, null, '2026-03-15', 2, 3, 1, 0, 0.0),
            $this->delta('colour', 'k1', 'red', '2026-03-15', 2, 3, 1, 0, 0.0),
            $this->delta('score', null, null, '2026-03-15', 2, 3, 0, 1, 4.5),
        ]);
        $detail = fn (array $rows) => array_values(array_filter($rows, fn ($r) => $r['is_rollup'] === 0));

        $this->assertSame([
            ['grain' => 0, 'period' => '2026-03-15', 'store_id' => 2, 'customer_group_id' => 3, 'is_rollup' => 0, 'submissions' => 1],
            ['grain' => 1, 'period' => '2026-03-01', 'store_id' => 2, 'customer_group_id' => 3, 'is_rollup' => 0, 'submissions' => 1],
        ], $detail($merged['day']));
        $this->assertSame([
            ['field_key' => 'colour', 'grain' => 0, 'period' => '2026-03-15', 'store_id' => 2, 'customer_group_id' => 3, 'is_rollup' => 0, 'value_key' => 'k1', 'hits' => 1],
            ['field_key' => 'colour', 'grain' => 1, 'period' => '2026-03-01', 'store_id' => 2, 'customer_group_id' => 3, 'is_rollup' => 0, 'value_key' => 'k1', 'hits' => 1],
        ], $detail($merged['value']));
        $this->assertSame([
            ['field_key' => 'score', 'grain' => 0, 'period' => '2026-03-15', 'store_id' => 2, 'customer_group_id' => 3, 'is_rollup' => 0, 'n' => 1, 'total' => 4.5],
            ['field_key' => 'score', 'grain' => 1, 'period' => '2026-03-01', 'store_id' => 2, 'customer_group_id' => 3, 'is_rollup' => 0, 'n' => 1, 'total' => 4.5],
        ], $detail($merged['number']));
        $this->assertSame([['field_key' => 'colour', 'value_key' => 'k1', 'value' => 'red']], $merged['label']);
    }

    public function testItWritesRollupRowsForAllStoresAndAllGroupsForBothGrains(): void
    {
        $merged = (new AggregateMerger())->merge([
            $this->delta('colour', 'k1', 'red', '2026-03-15', 2, 3, 1, 0, 0.0),
            $this->delta('colour', 'k1', 'red', '2026-03-15', 5, 0, 1, 0, 0.0),
        ]);
        $rollup = array_values(array_filter($merged['value'], fn ($r) => $r['is_rollup'] === 1));

        $this->assertCount(2, $rollup);
        foreach ($rollup as $row) {
            $this->assertSame([0, 0, 2], [$row['store_id'], $row['customer_group_id'], $row['hits']]);
        }
        $this->assertSame([0, 1], array_column($rollup, 'grain'));
        $this->assertCount(4, array_filter($merged['value'], fn ($r) => $r['is_rollup'] === 0));
    }

    private function delta(
        string $key,
        ?string $value,
        ?string $label,
        string $day,
        int $store,
        int $group,
        int $hits,
        int $n,
        float $total
    ): array {
        return ['k' => $key, 'v' => $value, 'l' => $label, 'p' => $day, 's' => $store, 'g' => $group,
            'h' => $hits, 'n' => $n, 't' => $total];
    }
}
