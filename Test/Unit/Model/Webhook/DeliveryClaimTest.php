<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\DeliveryClaim;
use Furan\FormBuilder\Model\Webhook\DeliveryStatus;
use Magento\Framework\App\ResourceConnection;
use PHPUnit\Framework\TestCase;

class DeliveryClaimTest extends TestCase
{
    private function claim(WebhookFixtures $f): DeliveryClaim
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($f->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new DeliveryClaim($resource);
    }

    public function testItClaimsADueDeliveryOnceWhenTwoWorkersRace(): void
    {
        $f = new WebhookFixtures(fn (string $c): object => $this->createStub($c));
        $claim = $this->claim($f);

        // The database lets exactly one UPDATE of the same row match; the second sees an expired condition.
        $f->updateAffects = 1;
        $this->assertTrue($claim->claim(11, WebhookFixtures::NOW));
        $f->updateAffects = 0;
        $this->assertFalse($claim->claim(11, WebhookFixtures::NOW));

        [$table, $bind, $where] = $f->updates[0];
        $this->assertSame('furan_formbuilder_webhook_delivery', $table);
        $this->assertSame(DeliveryStatus::DELIVERING, $bind['status']);
        $this->assertSame(gmdate('Y-m-d H:i:s', WebhookFixtures::NOW + 120), $bind['next_attempt_at']);
        $this->assertSame(
            [
                'delivery_id = ?' => 11,
                'status IN (?)' => [DeliveryStatus::PENDING, DeliveryStatus::DELIVERING],
                'next_attempt_at <= ?' => gmdate('Y-m-d H:i:s', WebhookFixtures::NOW),
            ],
            $where
        );
    }

    public function testItReclaimsADeliveryWhoseLeaseExpired(): void
    {
        $f = new WebhookFixtures(fn (string $c): object => $this->createStub($c));
        $claim = $this->claim($f);

        // A crashed worker left status delivering with next_attempt_at = its lease end, now in the past.
        $this->assertTrue($claim->claim(11, WebhookFixtures::NOW + 121));
        $this->assertContains(DeliveryStatus::DELIVERING, $f->updates[0][2]['status IN (?)']);
    }

    public function testItSeeksDueRowsOfOneEndpointByKeysetOnTheStatusEndpointIndex(): void
    {
        $queries = [];
        $connection = $this->createStub(\Magento\Framework\DB\Adapter\Pdo\Mysql::class);
        $connection->method('fetchAll')->willReturnCallback(function (string $sql, array $bind) use (&$queries): array {
            $queries[] = [$sql, $bind];
            return [['delivery_id' => '5', 'next_attempt_at' => '2027-01-15 08:00:00']];
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $claim = new DeliveryClaim($resource);
        $now = gmdate('Y-m-d H:i:s', WebhookFixtures::NOW);

        $first = $claim->dueForEndpoint(DeliveryStatus::PENDING, 7, WebhookFixtures::NOW, 20, null);
        $next = $claim->dueForEndpoint(DeliveryStatus::DELIVERING, 7, WebhookFixtures::NOW, 20, [$now, 5]);

        $this->assertSame([['delivery_id' => 5, 'next_attempt_at' => '2027-01-15 08:00:00']], $first);
        $this->assertCount(1, $next);
        $this->assertSame([DeliveryStatus::PENDING, 7, $now], $queries[0][1]);
        $this->assertStringContainsString('status = ? AND endpoint_id = ? AND next_attempt_at <= ?', $queries[0][0]);
        $this->assertStringContainsString('ORDER BY next_attempt_at, delivery_id LIMIT 20', $queries[0][0]);
        $this->assertSame([DeliveryStatus::DELIVERING, 7, $now, $now, $now, 5], $queries[1][1]);
        $this->assertStringContainsString('next_attempt_at >= ? AND (next_attempt_at > ? OR delivery_id > ?)', $queries[1][0]);
        foreach ($queries as [$sql]) {
            $this->assertStringNotContainsString('OFFSET', $sql);
            $this->assertStringNotContainsString(' IN (', $sql);
            $this->assertStringNotContainsString('COUNT', $sql);
        }
    }

    public function testItCancelsAPendingBatchOfAnEndpointByIdListWithoutUpdateLimit(): void
    {
        $queries = [];
        $connection = $this->createMock(\Magento\Framework\DB\Adapter\Pdo\Mysql::class);
        $connection->method('fetchCol')->willReturnCallback(function (string $sql, array $bind) use (&$queries): array {
            $queries[] = [$sql, $bind];
            return ['4', '9'];
        });
        $connection->expects($this->once())->method('update')->with(
            'furan_formbuilder_webhook_delivery',
            ['status' => DeliveryStatus::CANCELLED, 'last_error' => 'endpoint_inactive'],
            ['delivery_id IN (?)' => [4, 9], 'status = ?' => DeliveryStatus::PENDING]
        )->willReturn(2);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->assertSame(2, (new DeliveryClaim($resource))->cancelPending(7, 500));

        $this->assertSame([DeliveryStatus::PENDING, 7, 'webhook.test'], $queries[0][1]);
        $this->assertStringContainsString('status = ? AND endpoint_id = ? AND event <> ?', $queries[0][0]);
        $this->assertStringContainsString('ORDER BY next_attempt_at, delivery_id LIMIT 500', $queries[0][0]);
    }

    public function testItCancelsNothingWhenNoPendingRowsAreLeft(): void
    {
        $connection = $this->createMock(\Magento\Framework\DB\Adapter\Pdo\Mysql::class);
        $connection->method('fetchCol')->willReturn([]);
        $connection->expects($this->never())->method('update');
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->assertSame(0, (new DeliveryClaim($resource))->cancelPending(7, 500));
    }

    public function testItCancelsOneOpenDeliveryByPrimaryKey(): void
    {
        $f = new WebhookFixtures(fn (string $c): object => $this->createStub($c));

        $this->claim($f)->cancel(11, 'endpoint_inactive');

        $this->assertSame(
            [
                'furan_formbuilder_webhook_delivery',
                ['status' => DeliveryStatus::CANCELLED, 'last_error' => 'endpoint_inactive'],
                ['delivery_id = ?' => 11, 'status IN (?)' => [DeliveryStatus::PENDING, DeliveryStatus::DELIVERING]],
            ],
            $f->updates[0]
        );
    }

    public function testItLooksUpTheEndpointsOfWokenDeliveriesByPrimaryKey(): void
    {
        $connection = $this->createMock(\Magento\Framework\DB\Adapter\Pdo\Mysql::class);
        $connection->expects($this->once())->method('fetchPairs')->with(
            'SELECT delivery_id, endpoint_id FROM furan_formbuilder_webhook_delivery WHERE delivery_id IN (3,4)'
        )->willReturn(['3' => '9']);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->assertSame([3 => 9], (new DeliveryClaim($resource))->endpointsOf([3, 4]));
    }
}
