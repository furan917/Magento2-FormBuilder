<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Cron;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Cron\DraftCleanup;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DraftCleanupTest extends TestCase
{
    /** @var list<array{string, array}> */
    private array $queries = [];

    /**
     * @param list<int> $rowCounts rows removed by each successive batch (the last one repeats)
     * @param array<int, int> $daysByStore lifetime per store id
     */
    private function cleanup(
        array $rowCounts,
        array $daysByStore = [0 => 30, 1 => 30],
        bool $lockFree = true,
        ?callable $clock = null
    ): DraftCleanup {
        $this->queries = [];
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('query')->willReturnCallback(function (string $sql, array $bind) use ($rowCounts) {
            $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
            $statement->method('rowCount')->willReturn($rowCounts[count($this->queries)] ?? end($rowCounts));
            $this->queries[] = [$sql, $bind];
            return $statement;
        });
        $connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, array $ids): string => str_replace('?', implode(',', $ids), $text)
        );
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $t): string => 'pfx_' . $t);
        $config = $this->createStub(Config::class);
        $config->method('getServerDraftDays')->willReturnCallback(
            static fn (?int $storeId): int => $daysByStore[$storeId] ?? 0
        );
        $stores = [];
        foreach (array_keys($daysByStore) as $id) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn((string)$id);
            $stores[] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->with(true)->willReturn($stores);

        $locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->with(DraftCleanup::LOCK_NAME, 0)->willReturn($lockFree);

        return new DraftCleanup(
            $resource,
            $config,
            $storeManager,
            $this->createStub(LoggerInterface::class),
            $locks,
            $clock
        );
    }

    private function cutoffFor(int $days, int $before, int $after): array
    {
        return [gmdate('Y-m-d H:i:s', $before - $days * 86400), gmdate('Y-m-d H:i:s', $after - $days * 86400)];
    }

    public function testItRemovesDraftsOlderThanTheConfiguredLifetimeInBoundedBatches(): void
    {
        $before = time();
        $this->cleanup([500, 500, 7], [0 => 10, 1 => 10])->execute();
        $after = time();

        $this->assertCount(3, $this->queries);
        [$sql, $bind] = $this->queries[0];
        $this->assertSame(
            'DELETE FROM pfx_furan_formbuilder_draft WHERE store_id IN (0,1) AND updated_at < ? LIMIT 500',
            $sql
        );
        $this->assertContains($bind[0], $this->cutoffFor(10, $before, $after));
    }

    public function testItAppliesEachStoresOwnLifetimeAndGroupsStoresThatShareOne(): void
    {
        $before = time();
        $this->cleanup([3], [0 => 30, 1 => 7, 2 => 30, 3 => 7, 4 => 90])->execute();
        $after = time();

        $this->assertCount(3, $this->queries);
        $expected = [['0,2', 30], ['1,3', 7], ['4', 90]];
        foreach ($this->queries as $i => [$sql, $bind]) {
            [$stores, $days] = $expected[$i];
            $this->assertStringContainsString('store_id IN (' . $stores . ') AND', $sql);
            $this->assertContains($bind[0], $this->cutoffFor($days, $before, $after));
        }
    }

    public function testItDrainsOneLifetimeGroupBeforeMovingToTheNext(): void
    {
        $this->cleanup([500, 4, 2], [1 => 7, 2 => 30])->execute();

        $this->assertCount(3, $this->queries);
        $this->assertStringContainsString('store_id IN (1)', $this->queries[1][0]);
        $this->assertStringContainsString('store_id IN (2)', $this->queries[2][0]);
    }

    public function testItSkipsTheRunWhileAnotherNodeHoldsTheLock(): void
    {
        $this->cleanup([500], [0 => 30], false)->execute();
        $this->assertSame([], $this->queries);
    }

    public function testItStopsAtTheTimeBudgetSoABigBacklogIsSpreadOverRuns(): void
    {
        $calls = 0;
        $this->cleanup([500], [0 => 30], true, static function () use (&$calls): float {
            return 100.0 * $calls++;
        })->execute();
        $this->assertCount(2, $this->queries);
    }

    public function testItSharesOneTimeBudgetAcrossAllLifetimeGroups(): void
    {
        $calls = 0;
        $this->cleanup([2, 500], [1 => 7, 2 => 30], true, static function () use (&$calls): float {
            return 100.0 * $calls++;
        })->execute();

        $this->assertCount(2, $this->queries, 'the second group gets only what the first left of the budget');
        $this->assertStringContainsString('store_id IN (2)', $this->queries[1][0]);
    }
}
