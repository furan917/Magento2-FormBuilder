<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Cron;

use Furan\FormBuilder\Model\Cron\CounterCleanup;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CounterCleanupTest extends TestCase
{
    /** @var list<array{string, array}> */
    private array $queries = [];

    private function cleanup(array $rowCounts, bool $lockFree = true): CounterCleanup
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('query')->willReturnCallback(function (string $sql, array $bind) use ($rowCounts) {
            $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
            $statement->method('rowCount')->willReturn($rowCounts[count($this->queries)] ?? end($rowCounts));
            $this->queries[] = [$sql, $bind];
            return $statement;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $t): string => 'pfx_' . $t);
        $locks = $this->createStub(LockManagerInterface::class);
        $locks->method('lock')->willReturnCallback(fn (string $name, int $timeout): bool => $name === CounterCleanup::LOCK_NAME && $timeout === 0 && $lockFree);

        return new CounterCleanup($resource, $this->createStub(LoggerInterface::class), $locks, fn (): int => 5000);
    }

    public function testItRemovesExpiredCountersInBoundedBatches(): void
    {
        $this->cleanup([1000, 1000, 3])->execute();

        $this->assertCount(3, $this->queries);
        $this->assertSame(['DELETE FROM pfx_furan_formbuilder_counter WHERE expires_at < ? LIMIT 1000', [5000]], $this->queries[0]);
    }

    public function testItSkipsTheRunWhileAnotherNodeHoldsTheLock(): void
    {
        $this->cleanup([5], false)->execute();

        $this->assertSame([], $this->queries);
    }
}
