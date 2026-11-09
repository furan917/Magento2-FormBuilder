<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Export;

use Furan\FormBuilder\Model\Export\ExportQueue;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExportQueueTest extends TestCase
{
    private function queue(AdapterInterface $connection, PublisherInterface $publisher): ExportQueue
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new ExportQueue($resource, $publisher, new Json(), $this->createStub(LoggerInterface::class));
    }

    public function testItCapsTheSizeProbeInsteadOfCountingTheWholeForm(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('fetchOne')
            ->with($this->stringContains('WHERE form_id = ? LIMIT ' . (ExportQueue::INLINE_LIMIT + 1) . ') t'), [7])
            ->willReturn((string)(ExportQueue::INLINE_LIMIT + 1));

        $this->assertTrue($this->queue($connection, $this->createStub(PublisherInterface::class))->isLarge(7));
    }

    public function testItPublishesTheFormIdAndReportsABrokerFailure(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->exactly(2))->method('publish')->with(ExportQueue::TOPIC, '{"form_id":7}')
            ->willReturnOnConsecutiveCalls(null, $this->throwException(new \RuntimeException('down')));
        $queue = $this->queue($this->createStub(AdapterInterface::class), $publisher);

        $this->assertTrue($queue->request(7));
        $this->assertFalse($queue->request(7));
    }
}
