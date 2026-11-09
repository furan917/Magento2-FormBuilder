<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Stats;

use Furan\FormBuilder\Model\Stats\Bucket;
use Furan\FormBuilder\Model\Stats\DeltaCalculator;
use Furan\FormBuilder\Model\Stats\Journal;
use Furan\FormBuilder\Model\Stats\SeriesRegistry;
use Furan\FormBuilder\Model\Stats\SeriesSpec;
use Furan\FormBuilder\Test\Unit\Model\Submission\SubmissionStub;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class JournalTest extends TestCase
{
    private AdapterInterface&Stub $connection;
    private SeriesRegistry&Stub $registry;
    private Journal $journal;
    /** @var array<int, array> */
    private array $inserted = [];

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?SeriesRegistry $registry = null): void
    {
        $this->connection = $this->createStub(AdapterInterface::class);
        $this->connection->method('insert')->willReturnCallback(function (string $table, array $row): int {
            $this->inserted[] = [$table, $row];
            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->registry = $this->configureRegistry($registry ?? $this->createStub(SeriesRegistry::class));
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn('UTC');
        $this->journal = new Journal($resource, new DeltaCalculator(new Bucket()), $this->registry, new Json(), $config);
    }

    private function stored(string $colour): SubmissionStub
    {
        return (new SubmissionStub())
            ->setFormId(7)
            ->setStoreId(2)
            ->setCustomerGroupId(1)
            ->setCreatedAt('2026-03-01 10:00:00')
            ->setPayload([['name' => 'colour', 'label' => 'Colour', 'type' => 'select', 'value' => $colour]]);
    }

    /** @return array<int, array> */
    private function deltas(int $index = 0): array
    {
        $this->assertSame('furan_formbuilder_stat_journal', $this->inserted[$index][0]);
        return (new Json())->unserialize($this->inserted[$index][1]['deltas'])['d'];
    }

    private function byKey(array $deltas): array
    {
        $out = [];
        foreach ($deltas as $d) {
            $out[$d['k'] . '|' . ($d['v'] === null ? '' : 'v')] = $d;
        }
        return $out;
    }

    /**
     * @template T of SeriesRegistry&Stub
     * @param T $registry
     * @return T
     */
    private function configureRegistry(SeriesRegistry&Stub $registry): SeriesRegistry&Stub
    {
        $registry->method('load')->willReturn([
            'colour' => ['kind' => SeriesSpec::KIND_CHOICE, 'bucket_step' => null],
        ]);
        return $registry;
    }

    public function testItWritesAJournalRowWhenANewSubmissionIsStored(): void
    {
        $this->journal->add($this->stored('red'), [
            'colour' => ['kind' => SeriesSpec::KIND_CHOICE, 'bucket_step' => null],
        ]);

        $this->assertCount(1, $this->inserted);
        $this->assertSame(7, $this->inserted[0][1]['form_id']);
        $deltas = $this->deltas();
        $this->assertSame(['colour', '__submissions'], array_column($deltas, 'k'));
        $this->assertSame([1, 1], array_column($deltas, 'h'));
        $this->assertSame([2, 2], array_column($deltas, 's'));
        $this->assertSame([1, 1], array_column($deltas, 'g'));
        $this->assertSame('2026-03-01', $deltas[0]['p']);
    }

    public function testItRecordsAReplacementAsARemovalPlusAnAdditionWithTheOriginalDimensions(): void
    {
        $this->journal->replace($this->stored('red'), [
            ['name' => 'colour', 'label' => 'Colour', 'type' => 'select', 'value' => 'blue'],
        ]);

        $this->assertCount(1, $this->inserted);
        $deltas = $this->deltas();
        $hits = array_column($deltas, 'h', 'l');
        $this->assertSame([-1, 1], [$hits['red'], $hits['blue']]);
        $this->assertSame(['2026-03-01'], array_values(array_unique(array_column($deltas, 'p'))));
        $this->assertSame([2], array_values(array_unique(array_column($deltas, 's'))));
        $this->assertSame([1], array_values(array_unique(array_column($deltas, 'g'))));
        $this->assertNotContains('__submissions', array_column($deltas, 'k'));
    }

    public function testItMergesRemovalsOfRawStoredRowsIntoOneJournalRowPerForm(): void
    {
        $json = new Json();
        $row = static fn (int $form, string $colour, ?int $store): array => [
            'form_id' => $form,
            'payload_json' => $json->serialize([['name' => 'colour', 'value' => $colour]]),
            'created_at' => '2026-03-01 10:00:00',
            'store_id' => $store,
            'customer_group_id' => 0,
        ];

        $this->journal->remove([$row(7, 'red', 2), $row(7, 'red', 2), $row(8, 'red', null)]);

        $this->assertCount(2, $this->inserted);
        $this->assertSame([7, 8], array_column(array_column($this->inserted, 1), 'form_id'));
        $first = $this->byKey($this->deltas(0));
        $this->assertSame(-2, $first['colour|v']['h']);
        $this->assertSame(-2, $first['__submissions|']['h']);
        $this->assertCount(2, $this->deltas(0));
        $this->assertSame(0, $this->deltas(1)[0]['s']);
    }

    public function testItWritesNoRowWhenThereAreNoDeltas(): void
    {
        $this->journal->replace($this->stored('red'), [
            ['name' => 'colour', 'label' => 'Colour', 'type' => 'select', 'value' => 'red'],
        ]);

        $this->assertSame([], $this->inserted);
    }

    public function testItLoadsSeriesSpecsOncePerFormForABatchOfRemovals(): void
    {
        $registry = $this->createMock(SeriesRegistry::class);
        $registry->expects($this->exactly(2))->method('load')->willReturn([]);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn('UTC');
        $journal = new Journal($resource, new DeltaCalculator(new Bucket()), $registry, new Json(), $config);
        $row = static fn (int $form): array => [
            'form_id' => $form, 'payload_json' => '[]', 'created_at' => '2026-03-01 10:00:00',
            'store_id' => 1, 'customer_group_id' => 0,
        ];

        $journal->remove(array_merge(array_fill(0, 300, $row(7)), array_fill(0, 200, $row(8))));

        $this->assertCount(2, $this->inserted);
        $this->assertSame(-300, $this->byKey($this->deltas(0))['__submissions|']['h']);
    }

    public function testItUsesSpecsPassedByABatchCallerInsteadOfLoadingThemPerRow(): void
    {
        $registry = $this->createMock(SeriesRegistry::class);
        $this->fixture(registry: $registry);
        $registry->expects($this->never())->method('load');

        $this->journal->replace($this->stored('red'), [
            ['name' => 'colour', 'label' => 'Colour', 'type' => 'select', 'value' => 'blue'],
        ], ['colour' => ['kind' => SeriesSpec::KIND_CHOICE, 'bucket_step' => null]]);

        $this->assertCount(1, $this->inserted);
    }
}
