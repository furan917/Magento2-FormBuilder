<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Action;

use Furan\FormBuilder\Model\Action\ActionConsumer;
use Furan\FormBuilder\Model\Action\ActionQueue;
use Furan\FormBuilder\Model\Action\RetryDrainer;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RetryDrainerTest extends TestCase
{
    private const NOW = 1800000000;

    /** @var array<int, string> */
    private array $events = [];
    /** @var array<int, array{0: string, 1: array}> */
    private array $selects = [];
    private ActionConsumer&Stub $consumer;
    private ActionQueue&Stub $queue;
    private LockManagerInterface&Stub $locks;

    protected function setUp(): void
    {
        $this->events = [];
        $this->selects = [];
        $this->consumer = $this->createStub(ActionConsumer::class);
        $this->queue = $this->createStub(ActionQueue::class);
        $this->locks = $this->configureLocks($this->createStub(LockManagerInterface::class));
    }

    /** @param array<int, array<string, int|string>> $rows */
    private function drainer(array $rows): RetryDrainer
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchAll')->willReturnCallback(function (string $sql, array $bind) use ($rows): array {
            $this->selects[] = [$sql, $bind];
            return $rows;
        });
        $connection->method('delete')->willReturnCallback(function (string $table, array $where): int {
            $this->events[] = 'delete:' . array_values($where)[0];
            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new RetryDrainer(
            $resource,
            $this->queue,
            $this->consumer,
            $this->locks,
            $this->createStub(LoggerInterface::class),
            static fn (): int => self::NOW
        );
    }

    private function row(int $retry, int $submission, string $action, int $attempt, bool $edit = false): array
    {
        return ['retry_id' => (string)$retry, 'submission_id' => (string)$submission,
            'action_code' => $action, 'attempt' => (string)$attempt, 'is_edit' => $edit ? '1' : '0'];
    }

    /**
     * @template T of LockManagerInterface&Stub
     * @param T $locks
     * @return T
     */
    private function configureLocks(LockManagerInterface&Stub $locks): LockManagerInterface&Stub
    {
        $locks->method('lock')->willReturn(true);
        return $locks;
    }

    public function testItRunsDueRetriesInDueOrderWithABatchLimit(): void
    {
        $this->queue->method('publish')->willReturn(false);
        $this->consumer->method('run')->willReturnCallback(function (int $id, string $key, int $attempt, bool $edit): void {
            $this->events[] = "run:$id:$key:$attempt:" . var_export($edit, true);
        });

        $this->drainer([$this->row(7, 41, 'crm', 2), $this->row(9, 42, 'hook', 3, true)])->drain();

        [$sql, $bind] = $this->selects[0];
        $this->assertStringContainsString('SELECT retry_id, submission_id, action_code, attempt, is_edit FROM', $sql);
        $this->assertStringContainsString('due_at <= ?', $sql);
        $this->assertStringContainsString('ORDER BY due_at, retry_id LIMIT 200', $sql);
        $this->assertStringNotContainsString('OFFSET', $sql);
        $this->assertSame([gmdate('Y-m-d H:i:s', self::NOW)], $bind);
        $this->assertSame(
            ['delete:7', 'run:41:crm:2:false', 'delete:9', 'run:42:hook:3:true'],
            $this->events
        );
    }

    public function testItPublishesInsteadOfRunningWhenBackgroundActionsAreOn(): void
    {
        $queue = $this->queue = $this->createMock(ActionQueue::class);
        $consumer = $this->consumer = $this->createMock(ActionConsumer::class);
        $queue->expects($this->once())->method('publish')->with(41, 'crm', 2, true)->willReturn(true);
        $consumer->expects($this->never())->method('run');

        $this->drainer([$this->row(7, 41, 'crm', 2, true)])->drain();
    }

    public function testItDeletesARetryRowBeforeRunningTheAction(): void
    {
        $this->queue->method('publish')->willReturn(false);
        $this->consumer->method('run')->willThrowException(new \RuntimeException('boom'));

        $this->drainer([$this->row(7, 41, 'crm', 2), $this->row(9, 42, 'hook', 2)])->drain();

        $this->assertSame(['delete:7', 'delete:9'], $this->events, 'a crash in one run never blocks the next row');
    }

    public function testItSkipsTheDrainWhenAnotherNodeHoldsTheLock(): void
    {
        $locks = $this->locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->with(RetryDrainer::LOCK_NAME, 0)->willReturn(false);
        $locks->expects($this->never())->method('unlock');

        $this->drainer([$this->row(7, 41, 'crm', 2)])->drain();

        $this->assertSame([], $this->selects);
    }
}
