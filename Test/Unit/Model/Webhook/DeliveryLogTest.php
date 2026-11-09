<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\DeliveryIndex;
use Furan\FormBuilder\Model\Webhook\DeliveryLog;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class DeliveryLogTest extends TestCase
{
    /** @var list<array{0: string, 1: mixed}> */
    private array $calls = [];

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /** @var array<string, mixed>|false */
    private $deliveryRow = false;

    private string $hint = '';

    private string $statusHint = '';

    /** @var string|false submission_id the reference lookup returns */
    private $referenced = false;

    /** @var list<array{0: mixed, 1: mixed}> */
    private array $lookups = [];

    /** @var list<array{0: mixed, 1: mixed}> */
    private array $queries = [];

    private function log(string $prefix = ''): DeliveryLog
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'order', 'limit', 'joinLeft'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($method, $select): Select {
                $this->calls[] = [$method, $args];
                return $select;
            });
        }
        $connection = $this->createStub(Mysql::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturnCallback(fn () => $this->deliveryRow);
        $connection->method('fetchAll')->willReturnCallback(function ($sql = null, $bind = []): array {
            $this->queries[] = [$sql, $bind];
            return $this->rows;
        });
        $connection->method('fetchOne')->willReturnCallback(function ($sql = null, $bind = []) {
            $this->lookups[] = [$sql, $bind];
            return $this->referenced;
        });
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $n): string => '`' . $n . '`');
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $t): string => $prefix . $t);
        $index = $this->createStub(DeliveryIndex::class);
        $index->method('forEndpoint')->willReturnCallback(fn (): string => $this->hint);
        $index->method('forEndpointStatus')->willReturnCallback(fn (): string => $this->statusHint);

        return new DeliveryLog($resource, $index);
    }

    /** @return list<mixed> */
    private function args(string $method): array
    {
        return array_map(
            static fn (array $call): array => array_filter($call[1], static fn ($v): bool => $v !== null),
            array_values(array_filter($this->calls, static fn (array $call): bool => $call[0] === $method))
        );
    }

    private static function delivery(int $id): array
    {
        return [
            'delivery_id' => (string)$id, 'endpoint_id' => '7', 'submission_id' => '12', 'event' => 'submission.created',
            'webhook_id' => 'msg_abc', 'replay_of' => null, 'admin_user_id' => null, 'status' => '3', 'attempts' => '2',
            'last_status_code' => '500', 'last_error' => 'http', 'created_at' => '2026-11-08 10:00:00',
            'delivered_at' => null,
        ];
    }

    public function testItListsDeliveriesNewestFirstWithKeysetPaging(): void
    {
        $this->rows = array_map(self::delivery(...), [60, 59, 58]);
        $page = $this->log()->page(7, 61, 2);

        [[$sql, $bind]] = $this->queries;
        $this->assertStringContainsString('WHERE endpoint_id = ? AND delivery_id < ? ORDER BY delivery_id DESC LIMIT 3', $sql);
        $this->assertStringNotContainsString('OFFSET', $sql);
        $this->assertSame([7, 61], $bind);
        $this->assertSame([60, 59], array_column($page['rows'], 'id'));
        $this->assertSame(59, $page['next']);
        $this->assertSame('http', $page['rows'][0]['error']);
        $this->assertSame(500, $page['rows'][0]['code']);
    }

    public function testItNeedsNoCursorOnTheFirstPageAndHasNoNextOnTheLast(): void
    {
        $this->rows = [self::delivery(5)];
        $page = $this->log()->page(7, 0, 50);

        [[$sql, $bind]] = $this->queries;
        $this->assertStringContainsString('WHERE endpoint_id = ? ORDER BY', $sql);
        $this->assertSame([7], $bind);
        $this->assertNull($page['next']);
    }

    public function testItFiltersByStatusOnTheEndpointStatusIndexNewestFirst(): void
    {
        $this->statusHint = ' FORCE INDEX (`S`)';
        $this->rows = array_map(self::delivery(...), [60, 59, 58]);
        $page = $this->log()->page(7, 61, 2, 3);

        [[$sql, $bind]] = $this->queries;
        $this->assertStringContainsString(
            '` FORCE INDEX (`S`) WHERE endpoint_id = ? AND status = ? AND delivery_id < ? ORDER BY delivery_id DESC LIMIT 3',
            $sql
        );
        $this->assertSame([7, 3, 61], $bind);
        $this->assertSame(59, $page['next']);
        $this->assertFalse($page['unrecognised']);
    }

    public function testItFindsDeliveriesByWebhookIdWithoutForcingTheEndpointIndex(): void
    {
        $this->hint = ' FORCE INDEX (`E`)';
        $this->rows = [self::delivery(5)];
        $this->log()->page(7, 0, 50, null, '  MSG_0192F3A47B3C7D1E8A2F5C6D7E8F9A0B ');

        [[$sql, $bind]] = $this->queries;
        $this->assertStringNotContainsString('FORCE INDEX', $sql);
        $this->assertStringContainsString('WHERE endpoint_id = ? AND webhook_id = ? ORDER BY delivery_id DESC', $sql);
        $this->assertSame([7, 'msg_0192f3a47b3c7d1e8a2f5c6d7e8f9a0b'], $bind);
    }

    public function testItFindsDeliveriesBySubmissionIdWithOrWithoutAHash(): void
    {
        $this->rows = [self::delivery(5)];
        $this->log()->page(7, 0, 50, 3, '#12');
        $this->log()->page(7, 0, 50, null, '12');

        $this->assertStringContainsString('WHERE endpoint_id = ? AND submission_id = ? AND status = ? ORDER BY', $this->queries[0][0]);
        $this->assertSame([7, 12, 3], $this->queries[0][1]);
        $this->assertSame([7, 12], $this->queries[1][1]);
        $this->assertSame([], $this->lookups);
    }

    public function testItFindsDeliveriesByTheSubmissionReference(): void
    {
        $this->referenced = '12';
        $this->rows = [self::delivery(5)];
        $page = $this->log()->page(7, 0, 50, null, '0192f3a4-7b3c-7d1e-8a2f-5c6d7e8f9a0b');

        [[$lookup, $lookupBind]] = $this->lookups;
        $this->assertStringContainsString('furan_formbuilder_submission', $lookup);
        $this->assertStringContainsString('WHERE reference = ? LIMIT 1', $lookup);
        $this->assertSame([hex2bin('0192f3a47b3c7d1e8a2f5c6d7e8f9a0b')], $lookupBind);
        $this->assertSame([7, 12], $this->queries[0][1]);
        $this->assertCount(1, $page['rows']);
    }

    public function testItShowsNothingForAReferenceThatMatchesNoSubmission(): void
    {
        $page = $this->log()->page(7, 0, 50, null, '0192f3a47b3c7d1e8a2f5c6d7e8f9a0b');

        $this->assertSame([], $this->queries);
        $this->assertSame(['rows' => [], 'next' => null, 'unrecognised' => false], $page);
    }

    public function testItFlagsASearchItCannotReadAndRunsNoQuery(): void
    {
        foreach (['abc', 'msg_', '12345678901', '1 OR 1=1', str_repeat('9', 70)] as $find) {
            $this->assertSame(['rows' => [], 'next' => null, 'unrecognised' => true], $this->log()->page(7, 0, 50, null, $find), $find);
        }
        $this->assertSame([], $this->queries);
        $this->assertSame([], $this->lookups);
    }

    private static function attempt(int $id): array
    {
        return [
            'attempt_id' => (string)$id, 'started_at' => '2026-11-08 10:00:01', 'duration_ms' => '120',
            'status_code' => '301', 'error_class' => 'blocked', 'errno' => null, 'connected_ip' => '203.0.113.5',
            'location' => 'http://127.0.0.1/x', 'retry_after' => null, 'excerpt' => 'not found',
        ];
    }

    public function testItShowsAttemptsWithoutRequestHeadersAndTheDebugExcerptOnlyWhenAsked(): void
    {
        $this->rows = [self::attempt(1)];
        $without = $this->log()->attempts(9, false);
        $selected = $this->args('from');
        $this->assertSame(['a' => 'furan_formbuilder_webhook_attempt'], $selected[0][0]);
        $this->assertSame([], $this->args('joinLeft'));
        $this->assertNotContains('excerpt', $selected[0][1]);
        $this->assertSame([['a.delivery_id = ?', 9]], $this->args('where'));
        $this->assertArrayNotHasKey('excerpt', $without[0]);
        $this->assertSame('http://127.0.0.1/x', $without[0]['location']);
        $this->assertSame('203.0.113.5', $without[0]['connected_ip']);

        $this->calls = [];
        $with = $this->log()->attempts(9, true);
        $this->assertCount(1, $this->args('joinLeft'));
        $this->assertSame('not found', $with[0]['excerpt']);
        foreach ($this->calls as [, $args]) {
            $this->assertStringNotContainsStringIgnoringCase('header', json_encode($args, JSON_THROW_ON_ERROR));
        }
    }

    public function testItLoadsTheDeliveryDetailWithATablePrefix(): void
    {
        $this->rows = [self::attempt(1)];
        $this->log('pfx_')->attempts(9, true);

        $this->assertSame([['a' => 'pfx_furan_formbuilder_webhook_attempt']], array_column($this->args('from'), 0));
        $this->assertSame(
            [[['d' => 'pfx_furan_formbuilder_webhook_attempt_debug'], 'd.attempt_id = a.attempt_id', ['excerpt']]],
            array_map(array_values(...), $this->args('joinLeft'))
        );
        $this->assertSame([['a.attempt_id ASC']], $this->args('order'));
    }

    public function testItForcesTheEndpointIndexByItsColumnsWithATablePrefix(): void
    {
        $this->hint = ' FORCE INDEX (`PFX_IDX_1`)';
        $this->rows = [self::delivery(5)];
        $this->log('pfx_')->page(7, 0);
        $this->assertStringContainsString('FROM `pfx_furan_formbuilder_webhook_delivery` FORCE INDEX (`PFX_IDX_1`) WHERE', $this->queries[0][0]);

        $this->hint = '';
        $this->log('pfx_')->page(7, 0);
        $this->assertStringContainsString('FROM `pfx_furan_formbuilder_webhook_delivery` WHERE', $this->queries[1][0]);
    }

    public function testItReadsOneDeliveryRowByItsId(): void
    {
        $this->deliveryRow = self::delivery(9);
        $delivery = $this->log()->delivery(9);

        $this->assertSame(9, $delivery['id']);
        $this->assertSame(7, $delivery['endpoint_id']);
        $this->assertSame([['delivery_id = ?', 9]], $this->args('where'));
    }

    public function testItReturnsNothingForAnUnknownDelivery(): void
    {
        $this->assertNull($this->log()->delivery(404));
    }
}
