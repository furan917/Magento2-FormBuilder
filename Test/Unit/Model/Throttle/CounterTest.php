<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Throttle;

use Furan\FormBuilder\Model\Throttle\Counter;
use Furan\FormBuilder\Test\Unit\Helper\MemoryCounter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

class CounterTest extends TestCase
{
    public function testItIncrementsInOneUpsertAndReadsTheValueThisConnectionWrote(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('query')->with(
            $this->callback(function (string $sql): bool {
                $this->assertStringContainsString('INSERT INTO furan_formbuilder_counter', $sql);
                $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
                $this->assertStringContainsString('LAST_INSERT_ID(IF(expires_at <= ?, ?, hits + ?))', $sql);
                return true;
            }),
            $this->callback(function (array $bind): bool {
                $this->assertSame(hash('sha256', 'ffb_rl_x', true), $bind[0], 'Only a digest of the key is stored');
                $this->assertSame([3, 1060, 1000, 3, 3, 1000, 1060], array_slice($bind, 1));
                return true;
            })
        );
        $connection->expects($this->once())->method('fetchOne')->with('SELECT LAST_INSERT_ID()')->willReturn('7');
        $connection->expects($this->once())->method('beginTransaction');
        $connection->expects($this->once())->method('commit');
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->assertSame(7, (new Counter($resource, fn (): int => 1000))->hit('ffb_rl_x', 60, 3));
    }

    public function testItIgnoresExpiredRowsWhenPeeking(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('fetchOne')
            ->with($this->stringContains('expires_at > ?'), [hash('sha256', 'k', true), 1000])
            ->willReturn(false);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->assertSame(0, (new Counter($resource, fn (): int => 1000))->peek('k'));
    }

    public function testTheMemoryFakeRestartsAnExpiredWindow(): void
    {
        $now = 100;
        $counter = new MemoryCounter(function () use (&$now): int {
            return $now;
        });
        $this->assertSame(1, $counter->hit('k', 10));
        $this->assertSame(3, $counter->hit('k', 10, 2));
        $now = 110;
        $this->assertSame(0, $counter->peek('k'));
        $this->assertSame(1, $counter->hit('k', 10));
    }
}
