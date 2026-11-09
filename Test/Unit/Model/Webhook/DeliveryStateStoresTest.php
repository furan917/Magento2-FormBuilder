<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Api\Data\HttpResult;
use Furan\FormBuilder\Api\Data\RequestPolicy;
use Furan\FormBuilder\Model\Http\SafeHttpClient;
use Furan\FormBuilder\Api\Data\SafeUrl;
use Furan\FormBuilder\Model\Webhook\DeliveryStore;
use Furan\FormBuilder\Model\Webhook\EndpointState;
use Furan\FormBuilder\Model\Webhook\WebhookTransport;
use Magento\Framework\App\ResourceConnection;
use PHPUnit\Framework\TestCase;

class DeliveryStateStoresTest extends TestCase
{
    public function testItLoadsAndUpdatesOneDeliveryByPrimaryKey(): void
    {
        $f = new WebhookFixtures(fn (string $c): object => $this->createStub($c));
        $f->rows = [['delivery_id' => '11']];
        $store = new DeliveryStore($this->resource($f));

        $this->assertSame(['delivery_id' => '11'], $store->load(11));
        $f->rows = [];
        $this->assertNull($store->load(12));

        $store->update(11, ['status' => 2]);
        $this->assertSame(['furan_formbuilder_webhook_delivery', ['status' => 2], ['delivery_id = ?' => 11]], $f->updates[0]);
    }

    public function testItWritesTheFailureStreakAsAtomicExpressions(): void
    {
        $f = new WebhookFixtures(fn (string $c): object => $this->createStub($c));
        $state = new EndpointState($this->resource($f));

        $state->recordFailure(7, '2026-11-08 10:00:00', '2026-11-08 10:05:00');

        [$table, $bind, $where] = $f->updates[0];
        $this->assertSame('furan_formbuilder_webhook', $table);
        $this->assertSame('consecutive_failures + 1', (string)$bind['consecutive_failures']);
        $this->assertStringStartsWith('COALESCE(failing_since, ', (string)$bind['failing_since']);
        $this->assertSame('2026-11-08 10:05:00', $bind['circuit_open_until']);
        $this->assertSame(['endpoint_id = ?' => 7], $where);

        $state->reset(7);
        $this->assertSame(['consecutive_failures' => 0, 'failing_since' => null, 'circuit_open_until' => null], $f->updates[1][1]);
    }

    public function testItReportsWhetherThisCallSwitchedTheEndpointOff(): void
    {
        $f = new WebhookFixtures(fn (string $c): object => $this->createStub($c));
        $state = new EndpointState($this->resource($f));

        $this->assertTrue($state->disable(7, 'gone'));
        $this->assertSame(['is_active' => 0, 'disabled_reason' => 'gone'], $f->updates[0][1]);
        $this->assertSame(['endpoint_id = ?' => 7, 'is_active = 1'], $f->updates[0][2]);

        $f->updateAffects = 0;
        $this->assertFalse($state->disable(7, 'gone'));
    }

    public function testItSendsThroughTheSafeClient(): void
    {
        $url = new SafeUrl('https', 'a.example.com', 443, '/x', false);
        $policy = new RequestPolicy();
        $result = new HttpResult(200);
        $client = $this->createMock(SafeHttpClient::class);
        $client->expects($this->once())->method('request')
            ->with('POST', $url, $policy, ['A' => 'b'], '{}')->willReturn($result);

        $this->assertSame($result, (new WebhookTransport($client))->send($url, $policy, ['A' => 'b'], '{}'));
    }

    private function resource(WebhookFixtures $f): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($f->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }
}
