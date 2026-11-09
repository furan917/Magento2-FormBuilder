<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Stats;

use Furan\FormBuilder\Model\Stats\AggregateMerger;
use Furan\FormBuilder\Model\Stats\Bucket;
use Furan\FormBuilder\Model\Stats\DeltaCalculator;
use Furan\FormBuilder\Model\Stats\SeriesSpec;
use PHPUnit\Framework\TestCase;

class FolderRecountTest extends TestCase
{
    private int $seed = 20261106;

    private const SPECS = [
        'colour' => ['kind' => SeriesSpec::KIND_CHOICE, 'bucket_step' => null],
        'tags' => ['kind' => SeriesSpec::KIND_MULTI, 'bucket_step' => null],
        'score' => ['kind' => SeriesSpec::KIND_DISCRETE, 'bucket_step' => null],
        'age' => ['kind' => SeriesSpec::KIND_NUMBER, 'bucket_step' => 10.0],
    ];

    public function testItMatchesABruteForceRecountAfterRandomSubmitsEditsAndDeletes(): void
    {
                $calculator = new DeltaCalculator(new Bucket());
        $merger = new AggregateMerger();
        $live = [];
        $journal = [];
        for ($step = 0; $step < 400; $step++) {
            $action = $live === [] ? 'add' : ['add', 'add', 'edit', 'delete'][$this->rand(0, 3)];
            $id = $action === 'add' ? $step : array_keys($live)[$this->rand(0, count($live) - 1)];
            if ($action === 'add') {
                $live[$id] = ['dims' => $this->randomDims(), 'payload' => $this->randomPayload()];
                $journal = [...$journal, ...$this->deltas($calculator, $live[$id], 1)];
            } elseif ($action === 'edit') {
                $journal = [...$journal, ...$this->deltas($calculator, $live[$id], -1)];
                $live[$id]['payload'] = $this->randomPayload();
                $journal = [...$journal, ...$this->deltas($calculator, $live[$id], 1)];
            } else {
                $journal = [...$journal, ...$this->deltas($calculator, $live[$id], -1)];
                unset($live[$id]);
            }
        }

        $folded = $this->nonZero($this->merge($merger, $journal));
        $recount = [];
        foreach ($live as $submission) {
            $recount = [...$recount, ...$this->deltas($calculator, $submission, 1)];
        }
        $this->assertEquals($this->nonZero($this->merge($merger, $recount)), $folded);

        $expectedPerDay = [];
        foreach ($live as $submission) {
            $key = substr($submission['dims']['created_at'], 0, 10) . '|' . $submission['dims']['store_id'] . '|'
                . $submission['dims']['customer_group_id'];
            $expectedPerDay[$key] = ($expectedPerDay[$key] ?? 0) + 1;
        }
        $actualPerDay = [];
        foreach ($folded['day'] as $row) {
            if ($row['grain'] === 0 && $row['is_rollup'] === 0) {
                $actualPerDay[$row['period'] . '|' . $row['store_id'] . '|' . $row['customer_group_id']]
                    = $row['submissions'];
            }
        }
        ksort($expectedPerDay);
        ksort($actualPerDay);
        $this->assertSame($expectedPerDay, $actualPerDay);
        $this->assertGreaterThan(0, count($live));
        $this->assertRollupsEqualDetailSums($folded);
    }

    private function assertRollupsEqualDetailSums(array $folded): void
    {
        $columns = ['day' => ['submissions'], 'value' => ['hits'], 'number' => ['n', 'total']];
        $identity = ['field_key', 'grain', 'period', 'value_key'];
        foreach ($columns as $table => $measures) {
            $sums = [];
            $rollups = [];
            foreach ($folded[$table] as $row) {
                $id = implode('|', array_map(fn ($c) => $row[$c] ?? '', $identity));
                foreach ($measures as $measure) {
                    if ($row['is_rollup'] === 1) {
                        $this->assertSame([0, 0], [$row['store_id'], $row['customer_group_id']]);
                        $rollups[$id][$measure] = $row[$measure];
                    } else {
                        $sums[$id][$measure] = ($sums[$id][$measure] ?? 0) + $row[$measure];
                    }
                }
            }
            $this->assertEqualsCanonicalizing(array_keys($sums), array_keys($rollups), $table);
            foreach ($sums as $id => $measured) {
                foreach ($measured as $measure => $sum) {
                    $this->assertEqualsWithDelta($sum, $rollups[$id][$measure], 1e-6, "$table $id $measure");
                }
            }
        }
    }

    private function merge(AggregateMerger $merger, array $deltas): array
    {
        return $merger->merge($deltas);
    }

    /** Zero cells are equivalent to absent rows after a removal; order is irrelevant. */
    private function nonZero(array $merged): array
    {
        $measures = ['submissions', 'hits', 'n', 'total'];
        foreach (['day', 'value', 'number'] as $table) {
            $rows = array_filter($merged[$table], function (array $row) use ($measures): bool {
                foreach ($measures as $measure) {
                    if (isset($row[$measure]) && abs($row[$measure]) > 1e-9) {
                        return true;
                    }
                }
                return false;
            });
            usort($rows, fn (array $a, array $b) => strcmp(json_encode(array_slice($a, 0, -1)), json_encode(array_slice($b, 0, -1))));
            $merged[$table] = array_values($rows);
        }
        $merged['label'] = [];
        return $merged;
    }

    private function deltas(DeltaCalculator $calculator, array $submission, int $sign): array
    {
        return $calculator->compute($submission['payload'], $submission['dims'], self::SPECS, $sign);
    }

    private function randomDims(): array
    {
        return [
            'form_id' => 1,
            'store_id' => $this->rand(1, 3),
            'customer_group_id' => $this->rand(0, 2),
            'created_at' => sprintf('2026-%02d-%02d 12:00:00', $this->rand(1, 3), $this->rand(1, 28)),
            'timezone' => 'UTC',
        ];
    }

    private function randomPayload(): array
    {
        $payload = [['name' => 'colour', 'value' => ['red', 'green', 'blue'][$this->rand(0, 2)]]];
        $payload[] = ['name' => 'tags', 'value' => array_slice(['a', 'b', 'c', 'd'], 0, $this->rand(0, 4))];
        $payload[] = ['name' => 'score', 'value' => (string)$this->rand(1, 5)];
        $payload[] = ['name' => 'age', 'value' => (string)$this->rand(0, 99)];
        return $payload;
    }

    /** Seeded LCG so failures reproduce. */
    private function rand(int $min, int $max): int
    {
        $this->seed = ($this->seed * 1103515245 + 12345) % 2147483648;
        return $min + intdiv($this->seed, 65536) % ($max - $min + 1);
    }
}
