<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\EndpointSlots;
use Magento\Framework\App\ResourceConnection;
use PHPUnit\Framework\TestCase;

class EndpointSlotsTest extends TestCase
{
    private function slots(WebhookFixtures $f): EndpointSlots
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($f->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new EndpointSlots($resource);
    }

    public function testItTakesSlotZeroWithACompareAndSwapLease(): void
    {
        $f = new WebhookFixtures(fn (string $c): object => $this->createStub($c));

        $this->assertSame(0, $this->slots($f)->acquire(7, WebhookFixtures::NOW));

        [$table, $bind, $where] = $f->updates[0];
        $this->assertSame('furan_formbuilder_webhook', $table);
        $this->assertSame(['slot0_until' => gmdate('Y-m-d H:i:s', WebhookFixtures::NOW + 120)], $bind);
        $this->assertSame(
            ['endpoint_id = ?' => 7, 'slot0_until IS NULL OR slot0_until < ?' => gmdate('Y-m-d H:i:s', WebhookFixtures::NOW)],
            $where
        );
    }

    public function testItFallsBackToSlotOneThenGivesUp(): void
    {
        $f = new WebhookFixtures(fn (string $c): object => $this->createStub($c));
        $results = [0, 1, 0, 0];
        $f->connection = $this->createStub(\Magento\Framework\DB\Adapter\Pdo\Mysql::class);
        $updates = [];
        $f->connection->method('update')->willReturnCallback(function ($t, $b) use (&$results, &$updates): int {
            $updates[] = array_keys($b)[0];
            return array_shift($results);
        });
        $slots = $this->slots($f);

        $this->assertSame(1, $slots->acquire(7, WebhookFixtures::NOW));
        $this->assertNull($slots->acquire(7, WebhookFixtures::NOW));
        $this->assertSame(['slot0_until', 'slot1_until', 'slot0_until', 'slot1_until'], $updates);
    }

    public function testItReleasesOnlyItsOwnLease(): void
    {
        $f = new WebhookFixtures(fn (string $c): object => $this->createStub($c));

        $this->slots($f)->release(7, 1, WebhookFixtures::NOW);

        $this->assertSame(['slot1_until' => null], $f->updates[0][1]);
        $this->assertSame(
            ['endpoint_id = ?' => 7, 'slot1_until = ?' => gmdate('Y-m-d H:i:s', WebhookFixtures::NOW + 120)],
            $f->updates[0][2]
        );
    }

    public function testItTellsAMissingEndpointRowFromBusySlotsByPrimaryKey(): void
    {
        $f = new WebhookFixtures(fn (string $c): object => $this->createStub($c));
        $f->connection = $this->createMock(\Magento\Framework\DB\Adapter\Pdo\Mysql::class);
        $f->connection->expects($this->exactly(2))->method('fetchOne')
            ->with('SELECT 1 FROM furan_formbuilder_webhook WHERE endpoint_id = ?', [7])
            ->willReturnOnConsecutiveCalls('1', false);
        $slots = $this->slots($f);

        $this->assertTrue($slots->endpointExists(7));
        $this->assertFalse($slots->endpointExists(7));
    }
}
