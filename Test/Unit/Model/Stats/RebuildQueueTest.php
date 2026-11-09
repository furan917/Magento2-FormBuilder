<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Stats;

use Furan\FormBuilder\Model\Stats\RebuildQueue;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class RebuildQueueTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private AdapterInterface&Stub $connection;

    private function queue(): RebuildQueue
    {
        $this->connection = $this->double('connection', AdapterInterface::class);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new RebuildQueue($resource);
    }

    public function testItQueuesARebuildForAFormOnAPostWithTheAnalyticsPermission(): void
    {
        $this->asserted = ['connection'];
        $queue = $this->queue();
        $this->connection->expects($this->once())->method('insertOnDuplicate')->with(
            'furan_formbuilder_stat_state',
            $this->callback(static fn (array $rows) => count($rows) === 1 && $rows[0]['form_id'] === 5
                && $rows[0]['rebuild_requested'] === 1
                && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $rows[0]['rebuild_requested_at']) === 1),
            ['rebuild_requested', 'rebuild_requested_at']
        );

        $this->assertSame(1, $queue->request(5));
    }

    public function testItQueuesEveryFormThatNeedsARebuildWhenNoFormIsGiven(): void
    {
        $this->asserted = ['connection'];
        $queue = $this->queue();
        $this->connection->expects($this->once())->method('fetchCol')
            ->with($this->stringContains('needs_rebuild = 1'))->willReturn(['3', '4']);
        $this->connection->expects($this->once())->method('insertOnDuplicate')->with(
            'furan_formbuilder_stat_state',
            $this->callback(static fn (array $rows) => array_column($rows, 'form_id') === [3, 4]),
            ['rebuild_requested', 'rebuild_requested_at']
        );

        $this->assertSame(2, $queue->request(null));
    }

    public function testItQueuesNothingWhenNoFormNeedsARebuild(): void
    {
        $this->asserted = ['connection'];
        $queue = $this->queue();
        $this->connection->method('fetchCol')->willReturn([]);
        $this->connection->expects($this->never())->method('insertOnDuplicate');

        $this->assertSame(0, $queue->request(null));
    }

    public function testItListsQueuedFormsOldestRequestFirst(): void
    {
        $this->asserted = ['connection'];
        $queue = $this->queue();
        $this->connection->expects($this->once())->method('fetchCol')->with($this->logicalAnd(
            $this->stringContains('rebuild_requested = 1'),
            $this->stringContains('ORDER BY rebuild_requested_at')
        ))->willReturn(['8', '2']);

        $this->assertSame([8, 2], $queue->queued());
    }

    public function testItReleasesAFormFromTheQueue(): void
    {
        $this->asserted = ['connection'];
        $queue = $this->queue();
        $this->connection->expects($this->once())->method('update')
            ->with('furan_formbuilder_stat_state', ['rebuild_requested' => 0], ['form_id = ?' => 8]);

        $queue->release(8);
    }

    public function testItReadsTheQueueStateOfAForm(): void
    {
        $queue = $this->queue();
        $this->connection->method('fetchRow')->willReturn(
            ['needs_rebuild' => '1', 'rebuilding' => '0', 'rebuild_requested' => '1', 'rebuild_requested_at' => '2026-11-07 01:00:00']
        );
        $this->assertSame(
            ['needs_rebuild' => true, 'rebuilding' => false, 'queued' => true, 'queued_at' => '2026-11-07 01:00:00'],
            $queue->state(3)
        );

        $queue = $this->queue();
        $this->connection->method('fetchRow')->willReturn(false);
        $this->assertSame(
            ['needs_rebuild' => false, 'rebuilding' => false, 'queued' => false, 'queued_at' => null],
            $queue->state(3)
        );
    }
}
