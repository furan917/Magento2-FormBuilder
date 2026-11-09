<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\DeliveryIndex;
use Furan\FormBuilder\Model\Webhook\Outbox;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

class DeliveryIndexTest extends TestCase
{
    private function index(array $list): DeliveryIndex
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('getIndexList')->with('pfx_' . Outbox::TABLE)->willReturn($list);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $n): string => '`' . $n . '`');
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $t): string => 'pfx_' . $t);

        return new DeliveryIndex($resource);
    }

    public function testItForcesTheEndpointIndexByItsColumnsWithATablePrefix(): void
    {
        $index = $this->index([
            'PRIMARY' => ['COLUMNS_LIST' => ['delivery_id']],
            'PFX_IDX_STATUS' => ['COLUMNS_LIST' => ['status', 'endpoint_id', 'next_attempt_at', 'delivery_id']],
            'PFX_IDX_9F8E7D' => ['COLUMNS_LIST' => ['endpoint_id', 'delivery_id']],
        ]);

        $this->assertSame(' FORCE INDEX (`PFX_IDX_9F8E7D`)', $index->forEndpoint());
        $this->assertSame(' FORCE INDEX (`PFX_IDX_9F8E7D`)', $index->forEndpoint(), 'looked up once');
    }

    public function testItForcesTheEndpointStatusIndexByItsColumns(): void
    {
        $index = $this->index([
            'PFX_IDX_STATUS' => ['COLUMNS_LIST' => ['status', 'endpoint_id', 'next_attempt_at', 'delivery_id']],
            'PFX_IDX_9F8E7D' => ['COLUMNS_LIST' => ['endpoint_id', 'delivery_id']],
            'PFX_IDX_ES' => ['COLUMNS_LIST' => ['endpoint_id', 'status', 'delivery_id']],
        ]);

        $this->assertSame(' FORCE INDEX (`PFX_IDX_ES`)', $index->forEndpointStatus());
        $this->assertSame(' FORCE INDEX (`PFX_IDX_9F8E7D`)', $index->forEndpoint(), 'one index list read for both');
    }

    public function testItGivesNoHintWhenTheIndexIsMissing(): void
    {
        $this->assertSame('', $this->index(['PRIMARY' => ['COLUMNS_LIST' => ['delivery_id']]])->forEndpoint());
    }
}
