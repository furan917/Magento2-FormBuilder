<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\DeliveryStatus;
use Furan\FormBuilder\Model\Webhook\RetentionPurger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RetentionPurgerTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const OLD = '2026-01-01 00:00:00';
    private const FRESH = '2027-01-15 00:00:00';

    /** @var array<int, array{status: int, created_at: string, endpoint_id: int}> */
    private array $deliveries = [];
    /** @var array<int, string> attempt id => created_at */
    private array $debug = [];
    /** @var int[] endpoints with purge_requested_at */
    private array $purging = [];
    /** @var list<string> */
    private array $log = [];
    /** @var list<array{string, array}> */
    private array $selects = [];
    private mixed $flag = null;
    private bool $lockFree = true;
    private int $retentionDays = 30;
    private int $tick = 0;

    private function purger(int $secondsPerClockRead = 0): RetentionPurger
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchAll')->willReturnCallback(fn (string $sql, array $bind = []): array => $this->select($sql, $bind));
        $connection->method('delete')->willReturnCallback(fn (string $table, array $where): int => $this->delete($table, $where));
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $t): string => 'pfx_' . $t);
        $flags = $this->createStub(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(fn (): mixed => $this->flag);
        $flags->method('saveFlag')->willReturnCallback(function (string $code, mixed $value): bool {
            $this->assertSame(RetentionPurger::FLAG_CODE, $code);
            $this->flag = $value;
            return true;
        });
        $locks = $this->createStub(LockManagerInterface::class);
        $locks->method('lock')->willReturnCallback(
            fn (string $name, int $timeout): bool => $name === RetentionPurger::LOCK_NAME && $timeout === 0 && $this->lockFree
        );
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(function (string $path, string $scope): mixed {
            $this->assertSame('furan_formbuilder/webhooks/retention_days', $path);
            $this->assertSame('default', $scope);
            return $this->retentionDays;
        });

        return new RetentionPurger(
            $resource,
            $flags,
            $locks,
            $config,
            $this->createStub(LoggerInterface::class),
            function () use ($secondsPerClockRead): int {
                $now = self::NOW + $this->tick * $secondsPerClockRead;
                $this->tick++;
                return $now;
            }
        );
    }

    /** @return array<int, array<string, string>> */
    private function select(string $sql, array $bind): array
    {
        $this->selects[] = [$sql, $bind];
        $this->assertStringNotContainsString('OFFSET', $sql);
        if (str_contains($sql, 'FROM pfx_furan_formbuilder_webhook_delivery WHERE delivery_id > ?')) {
            $rows = [];
            foreach ($this->deliveries as $id => $row) {
                if ($id > $bind[0]) {
                    $rows[] = ['delivery_id' => (string)$id, 'status' => (string)$row['status'], 'created_at' => $row['created_at']];
                }
            }
            return array_slice($rows, 0, 500);
        }

        if (str_contains($sql, 'FROM pfx_furan_formbuilder_webhook_attempt_debug WHERE created_at < ?')) {
            $rows = [];
            foreach ($this->debug as $id => $at) {
                if ($at < $bind[0] && [$at, $id] > [$bind[1], $bind[2]]) {
                    $rows[] = ['attempt_id' => (string)$id, 'created_at' => $at];
                }
            }
            usort($rows, static fn (array $x, array $y): int => [$x['created_at'], (int)$x['attempt_id']] <=> [$y['created_at'], (int)$y['attempt_id']]);
            return array_slice($rows, 0, 1000);
        }

        if (str_contains($sql, 'FROM pfx_furan_formbuilder_webhook WHERE purge_requested_at IS NOT NULL')) {
            return array_map(static fn (int $id): array => ['endpoint_id' => (string)$id], $this->purging);
        }
        if (str_contains($sql, 'FROM pfx_furan_formbuilder_webhook_delivery WHERE endpoint_id = ?')) {
            $rows = [];
            foreach ($this->deliveries as $id => $row) {
                if ($row['endpoint_id'] === $bind[0] && $id > $bind[1]) {
                    $rows[] = ['delivery_id' => (string)$id];
                }
            }
            return array_slice($rows, 0, 500);
        }

        return [];
    }

    private function delete(string $table, array $where): int
    {
        $key = array_key_first($where);
        $this->log[] = $table . '|' . $key . '|' . json_encode(array_values($where));
        if ($table === 'pfx_furan_formbuilder_webhook_delivery' && $key === 'delivery_id IN (?)') {
            foreach ($where[$key] as $id) {
                unset($this->deliveries[$id]);
            }
        }

        if ($table === 'pfx_furan_formbuilder_webhook_attempt_debug' && $key === 'attempt_id IN (?)') {
            foreach ($where[$key] as $id) {
                unset($this->debug[$id]);
            }
        }

        if ($table === 'pfx_furan_formbuilder_webhook' && $key === 'endpoint_id = ?') {
            $this->purging = array_values(array_diff($this->purging, [$where[$key]]));
        }

        return 1;
    }

    private function seed(int $from, int $to, int $status, string $createdAt, int $endpointId = 1): void
    {
        for ($id = $from; $id <= $to; $id++) {
            $this->deliveries[$id] = ['status' => $status, 'created_at' => $createdAt, 'endpoint_id' => $endpointId];
        }
    }

    public function testItPurgesFinalDeliveriesOlderThanTheRetentionPeriodInPrimaryKeyBatches(): void
    {
        $this->seed(1, 1200, DeliveryStatus::SUCCEEDED, self::OLD);
        $this->seed(1201, 1201, DeliveryStatus::SUCCEEDED, self::FRESH);

        $this->purger()->run();

        $this->assertSame([1201], array_keys($this->deliveries));
        $deletes = array_filter($this->log, static fn (string $l): bool => str_contains($l, 'webhook_delivery|delivery_id IN'));
        $this->assertCount(3, $deletes);
        $keyset = array_values(array_filter(
            $this->selects,
            static fn (array $s): bool => str_contains($s[0], 'FROM pfx_furan_formbuilder_webhook_delivery WHERE delivery_id > ?')
        ));
        $this->assertSame(
            'SELECT delivery_id, status, created_at FROM pfx_furan_formbuilder_webhook_delivery'
            . ' WHERE delivery_id > ? ORDER BY delivery_id LIMIT 500',
            $keyset[0][0]
        );
        $this->assertSame([[0], [500], [1000]], array_slice(array_column($keyset, 1), 0, 3));
    }

    public function testItKeepsPendingDeliveriesInsideTheDeliveryWindow(): void
    {
        $this->retentionDays = 1;
        $recent = gmdate('Y-m-d H:i:s', self::NOW - 2 * 86400);
        $this->seed(1, 3, DeliveryStatus::FAILED, self::OLD);
        $this->seed(4, 4, DeliveryStatus::PENDING, $recent);
        $this->seed(5, 5, DeliveryStatus::DELIVERING, $recent);
        $this->seed(6, 7, DeliveryStatus::CANCELLED, $recent);
        $this->seed(8, 8, DeliveryStatus::SUCCEEDED, self::FRESH);

        $this->purger()->run();

        $this->assertSame([4, 5, 8], array_keys($this->deliveries));
        $this->assertSame(3, $this->flag);

        // The pending row finalises later: a second run starts behind the kept row, not at zero.
        $this->deliveries[4]['status'] = DeliveryStatus::FAILED;
        $this->deliveries[5]['status'] = DeliveryStatus::BLOCKED;
        $this->selects = [];
        $this->purger()->run();

        $this->assertSame([8], array_keys($this->deliveries));
        $keyset = array_values(array_filter($this->selects, static fn (array $s): bool => str_contains($s[0], 'delivery_id > ?')));
        $this->assertSame([3], $keyset[0][1]);
    }

    public function testItPurgesAPendingDeliveryOlderThanTheDeliveryWindowAndTheRetentionPeriod(): void
    {
        $this->retentionDays = 1;
        $stuck = gmdate('Y-m-d H:i:s', self::NOW - 4 * 86400 - 60);
        $this->seed(1, 1, DeliveryStatus::PENDING, $stuck);
        $this->seed(2, 2, DeliveryStatus::DELIVERING, $stuck);
        $this->seed(3, 3, DeliveryStatus::PENDING, gmdate('Y-m-d H:i:s', self::NOW - 3 * 86400));
        $this->seed(4, 4, DeliveryStatus::SUCCEEDED, self::FRESH);

        $this->purger()->run();

        $this->assertSame([3, 4], array_keys($this->deliveries));
        $this->assertSame(2, $this->flag);

        $this->retentionDays = 30;
        $this->seed(5, 5, DeliveryStatus::PENDING, gmdate('Y-m-d H:i:s', self::NOW - 10 * 86400));
        $this->seed(6, 6, DeliveryStatus::PENDING, gmdate('Y-m-d H:i:s', self::NOW - 5 * 86400));
        $this->deliveries[3]['created_at'] = gmdate('Y-m-d H:i:s', self::NOW - 40 * 86400);
        $this->purger()->run();
        $this->assertSame([4, 5, 6], array_keys($this->deliveries), 'older than both cutoffs goes; inside retention stays');
    }

    public function testItNeverMovesTheCursorPastTheRetentionCutoff(): void
    {
        $this->seed(1, 2, DeliveryStatus::SUCCEEDED, self::OLD);
        $this->seed(3, 3, DeliveryStatus::SUCCEEDED, self::FRESH);

        $this->purger()->run();

        $this->assertSame(2, $this->flag);
    }

    public function testItReadsTheRetentionDaysFromTheDefaultScopeAndClampsThem(): void
    {
        $this->retentionDays = 400;
        $this->seed(1, 1, DeliveryStatus::SUCCEEDED, gmdate('Y-m-d H:i:s', self::NOW - 366 * 86400));
        $this->purger()->run();
        $this->assertSame([], array_keys($this->deliveries), '400 days is clamped to 365');

        $this->retentionDays = 0;
        $this->seed(2, 2, DeliveryStatus::SUCCEEDED, gmdate('Y-m-d H:i:s', self::NOW - 31 * 86400));
        $this->seed(3, 3, DeliveryStatus::SUCCEEDED, gmdate('Y-m-d H:i:s', self::NOW - 29 * 86400));
        $this->flag = null;
        $this->purger()->run();
        $this->assertSame([3], array_keys($this->deliveries), 'an invalid value falls back to 30 days');
    }

    public function testItPurgesDebugExcerptsAfterSevenDays(): void
    {
        $old = gmdate('Y-m-d H:i:s', self::NOW - 8 * 86400);
        $recent = gmdate('Y-m-d H:i:s', self::NOW - 6 * 86400);
        for ($id = 1; $id <= 2500; $id++) {
            $this->debug[$id] = $old;
        }
        $this->debug[2501] = $recent;

        $this->purger()->run();

        $this->assertSame([2501], array_keys($this->debug));
        $deletes = array_values(array_filter($this->log, static fn (string $l): bool => str_contains($l, 'attempt_debug|attempt_id IN')));
        $this->assertCount(3, $deletes);
        $select = array_values(array_filter($this->selects, static fn (array $s): bool => str_contains($s[0], 'attempt_debug')))[0];
        $this->assertSame(
            'SELECT attempt_id, created_at FROM pfx_furan_formbuilder_webhook_attempt_debug WHERE created_at < ?'
            . ' AND (created_at, attempt_id) > (?, ?) ORDER BY created_at, attempt_id LIMIT 1000',
            $select[0]
        );
        $this->assertSame([gmdate('Y-m-d H:i:s', self::NOW - 7 * 86400), '1000-01-01 00:00:00', 0], $select[1]);
    }

    public function testItPurgesDebugExcerptsWithACursor(): void
    {
        $old = gmdate('Y-m-d H:i:s', self::NOW - 8 * 86400);
        for ($id = 1; $id <= 1500; $id++) {
            $this->debug[$id] = $old;
        }

        $this->purger()->run();

        $this->assertSame([], $this->debug);
        $binds = array_column(array_values(array_filter(
            $this->selects,
            static fn (array $s): bool => str_contains($s[0], 'attempt_debug')
        )), 1);
        $this->assertSame([$old, 1000], array_slice($binds[1], 1));
        $this->assertCount(2, $binds, 'a short page ends the phase');
    }

    public function testItPurgesADeletedEndpointsDeliveriesInBatchesBeforeTheEndpointRow(): void
    {
        $this->purging = [5, 6];
        $this->seed(1, 1200, DeliveryStatus::PENDING, self::FRESH, 5);
        $this->seed(1201, 1201, DeliveryStatus::PENDING, self::FRESH, 7);

        $this->purger()->run();

        $this->assertSame([1201], array_keys($this->deliveries), 'any status of the purged endpoint goes; others stay');
        $this->assertSame([], $this->purging);
        $deletes = array_values(array_filter($this->log, static fn (string $l): bool => !str_contains($l, 'attempt_debug')));
        $this->assertSame(
            [
                'pfx_furan_formbuilder_webhook_delivery|delivery_id IN (?)',
                'pfx_furan_formbuilder_webhook_delivery|delivery_id IN (?)',
                'pfx_furan_formbuilder_webhook_delivery|delivery_id IN (?)',
                'pfx_furan_formbuilder_webhook|endpoint_id = ?',
                'pfx_furan_formbuilder_webhook|endpoint_id = ?',
            ],
            array_map(static fn (string $l): string => explode('|', $l, 3)[0] . '|' . explode('|', $l, 3)[1], $deletes)
        );
        $this->assertSame('pfx_furan_formbuilder_webhook|endpoint_id = ?|[5,"purge_requested_at IS NOT NULL"]', $deletes[3]);
    }

    public function testItPurgesADeletedEndpointsDeliveriesWithACursor(): void
    {
        $this->purging = [5];
        $this->seed(1, 1200, DeliveryStatus::PENDING, self::FRESH, 5);

        $this->purger()->run();

        $selects = array_values(array_filter(
            $this->selects,
            static fn (array $s): bool => str_contains($s[0], 'WHERE endpoint_id = ?')
        ));
        $this->assertSame(
            'SELECT delivery_id FROM pfx_furan_formbuilder_webhook_delivery WHERE endpoint_id = ? AND delivery_id > ?'
            . ' ORDER BY delivery_id LIMIT 500',
            $selects[0][0]
        );
        $this->assertSame([[5, 0], [5, 500], [5, 1000], [5, 1200]], array_column($selects, 1));
    }

    public function testItGivesTheDeletedEndpointPhaseItsOwnShareOfTheBudget(): void
    {
        $this->purging = [5];
        $this->seed(1, 3, DeliveryStatus::SUCCEEDED, self::OLD);
        $this->seed(100, 1299, DeliveryStatus::PENDING, self::FRESH, 5);

        $this->purger(25)->run();

        $this->assertSame([], array_intersect([1, 2, 3], array_keys($this->deliveries)), 'the delivery phase still ran');
        $this->assertCount(700, $this->deliveries, 'the endpoint phase stopped at its 60 second share');
        $this->assertSame([5], $this->purging);
        $first = array_values(array_filter($this->log, static fn (string $l): bool => !str_contains($l, 'attempt_debug')))[0];
        $this->assertStringContainsString('[100,', $first, 'the endpoint phase runs before the delivery phase');
    }

    public function testItKeepsADeletedEndpointRowWhileDeliveriesRemain(): void
    {
        $this->purging = [5];
        $this->seed(1, 1200, DeliveryStatus::PENDING, self::FRESH, 5);

        $this->purger(100)->run();

        $this->assertNotSame([], $this->deliveries);
        $this->assertSame([5], $this->purging);
    }

    public function testItSkipsTheRunWhileAnotherNodeHoldsTheLock(): void
    {
        $this->lockFree = false;
        $this->seed(1, 2, DeliveryStatus::SUCCEEDED, self::OLD);

        $this->purger()->run();

        $this->assertCount(2, $this->deliveries);
        $this->assertSame([], $this->log);
    }
}
