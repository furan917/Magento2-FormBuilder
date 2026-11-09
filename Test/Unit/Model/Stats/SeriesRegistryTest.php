<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Stats;

use Furan\FormBuilder\Model\Stats\SeriesRegistry;
use Furan\FormBuilder\Model\Stats\SeriesSpec;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SeriesRegistryTest extends TestCase
{
    private AdapterInterface&Stub $connection;
    private SeriesRegistry $registry;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?AdapterInterface $connection = null): void
    {
        $this->connection = $connection ?? $this->createStub(AdapterInterface::class);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->registry = new SeriesRegistry($resource, new SeriesSpec(\Furan\FormBuilder\Test\Unit\Helper\FieldPools::builtIn()));
    }

    public function testItCreatesAMissingSeriesSpecOnceAndReusesTheStoredOneAfterwards(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $fields = [
            ['name' => 'colour', 'type' => 'select'],
            ['name' => 'note', 'type' => 'textarea'],
        ];
        $stored = [['field_key' => 'colour', 'kind' => '1', 'bucket_step' => null]];
        $all = array_merge($stored, [['field_key' => 'note', 'kind' => '9', 'bucket_step' => null]]);
        $connection->expects($this->exactly(3))->method('fetchAll')
            ->willReturnOnConsecutiveCalls($stored, $all, $all);
        $connection->expects($this->once())->method('insertOnDuplicate')
            ->with(
                'furan_formbuilder_stat_field',
                [['form_id' => 7, 'field_key' => 'note', 'kind' => SeriesSpec::KIND_ANSWERED_ONLY, 'bucket_step' => null]],
                ['field_key']
            );

        $first = $this->registry->specsFor(7, $fields);
        $second = $this->registry->specsFor(7, $fields);

        $this->assertSame($first, $second);
        $this->assertSame(['kind' => 1, 'bucket_step' => null], $first['colour']);
        $this->assertSame(['kind' => 9, 'bucket_step' => null], $first['note']);
    }

    public function testItLoadsStoredStepsAsNumbers(): void
    {
        $this->connection->method('fetchAll')
            ->willReturn([['field_key' => 'n', 'kind' => '4', 'bucket_step' => '2.5']]);
        $this->assertSame(['n' => ['kind' => 4, 'bucket_step' => 2.5]], $this->registry->load(3));
    }

    /** @return array<int, array> */
    private function storedRows(): array
    {
        return [
            ['field_id' => '11', 'field_key' => 'colour', 'kind' => '1', 'bucket_step' => null],
            ['field_id' => '12', 'field_key' => 'age', 'kind' => '1', 'bucket_step' => null],
            ['field_id' => '13', 'field_key' => 'gone', 'kind' => '1', 'bucket_step' => null],
        ];
    }

    public function testItReDerivesTheSeriesSpecOfEveryCurrentSchemaFieldInPlace(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $this->connection->method('fetchAll')->willReturn($this->storedRows());
        $updates = [];
        $this->connection->method('update')->willReturnCallback(
            function (string $table, array $bind, $where) use (&$updates): int {
                $updates[] = [$table, $bind, $where];
                return 1;
            }
        );
        $connection->expects($this->never())->method('delete');
        $fields = [
            ['name' => 'colour', 'type' => 'select'],
            ['name' => 'age', 'type' => 'number'],
        ];

        $changed = $this->registry->resetSpecs(7, $fields);

        $spec = (new SeriesSpec(\Furan\FormBuilder\Test\Unit\Helper\FieldPools::builtIn()))->specFor($fields[1]);
        $this->assertSame(1, $changed);
        $this->assertSame(
            [['furan_formbuilder_stat_field', ['kind' => $spec['kind'], 'bucket_step' => $spec['bucket_step']], ['field_id = ?' => 12]]],
            $updates
        );
    }

    public function testItKeepsTheFieldIdOfAResetSeriesSoItsHistoryAndLabelsStayAttached(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $this->connection->method('fetchAll')->willReturn($this->storedRows());
        $connection->expects($this->never())->method('insertOnDuplicate');
        $connection->expects($this->never())->method('insertMultiple');
        $connection->expects($this->never())->method('delete');
        $connection->expects($this->never())->method('query');
        $connection->expects($this->once())->method('update')
            ->with($this->anything(), $this->anything(), ['field_id = ?' => 12]);

        $this->registry->resetSpecs(7, [['name' => 'age', 'type' => 'number']]);
    }

    public function testItLeavesTheSpecsOfFieldsNoLongerInTheSchemaUnchanged(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $this->connection->method('fetchAll')->willReturn($this->storedRows());
        $connection->expects($this->never())->method('update');

        $changed = $this->registry->resetSpecs(7, [['name' => 'colour', 'type' => 'select']]);

        $this->assertSame(0, $changed);
    }
}
