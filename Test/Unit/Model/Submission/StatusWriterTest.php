<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Model\Event\SubmissionEvents;
use Furan\FormBuilder\Model\Submission\StatusWriter;
use Furan\FormBuilder\Model\Webhook\SubmissionDeliveries;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class StatusWriterTest extends TestCase
{
    private AdapterInterface&Stub $connection;
    private SubmissionDeliveries&Stub $deliveries;
    private SubmissionEvents&Stub $events;
    /** @var string[] */
    private array $log = [];
    /** @var array<int, array<string, string|null>> */
    private array $rows = [];

    protected function setUp(): void
    {
        $this->log = [];
        $this->rows = [
            ['submission_id' => '4', 'form_id' => '3', 'store_id' => '2', 'status' => '0'],
            ['submission_id' => '5', 'form_id' => '8', 'store_id' => null, 'status' => '1'],
        ];
        $this->connection = $this->configureConnection($this->createStub(AdapterInterface::class));
        $this->deliveries = $this->createStub(SubmissionDeliveries::class);
        $this->events = $this->createStub(SubmissionEvents::class);
    }

    private function writer(): StatusWriter
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new StatusWriter($resource, $this->deliveries, $this->events);
    }

    /**
     * @template T of AdapterInterface&Stub
     * @param T $connection
     * @return T
     */
    private function configureConnection(AdapterInterface&Stub $connection): AdapterInterface&Stub
    {
        $connection->method('quoteInto')->willReturnCallback(
            static fn(string $text, $value): string => str_replace('?', is_array($value) ? implode(',', $value) : (string)$value, $text)
        );
        $connection->method('beginTransaction')->willReturnCallback(function (): AdapterInterface {
            $this->log[] = 'begin';
            return $this->connection;
        });
        $connection->method('commit')->willReturnCallback(function (): AdapterInterface {
            $this->log[] = 'commit';
            return $this->connection;
        });
        $connection->method('rollBack')->willReturnCallback(function (): AdapterInterface {
            $this->log[] = 'rollback';
            return $this->connection;
        });
        return $connection;
    }

    public function testItRecordsEverySubmissionOfAMassStatusChangeInTheBatch(): void
    {
        $deliveries = $this->deliveries = $this->createMock(SubmissionDeliveries::class);
        $this->connection->method('fetchAll')->willReturn($this->rows);
        $seen = [];
        $deliveries->expects($this->once())->method('recordMany')->willReturnCallback(
            function (string $event, array $items) use (&$seen): array {
                $seen = [$event, $items];
                return [40, 50];
            }
        );

        $this->writer()->change([4, 5], 2, 'admin');

        $this->assertSame([
            'submission.status_changed',
            [
                ['submissionId' => 4, 'formId' => 3, 'storeId' => 2, 'eventData' => ['old' => 0, 'new' => 2, 'source' => 'admin']],
                ['submissionId' => 5, 'formId' => 8, 'storeId' => null, 'eventData' => ['old' => 1, 'new' => 2, 'source' => 'admin']],
            ],
        ], $seen);
    }

    public function testItWritesTheStatusChangeAndItsDeliveriesInOneTransaction(): void
    {
        $this->connection->method('fetchAll')->willReturnCallback(function (): array {
            $this->log[] = 'select';
            return $this->rows;
        });
        $this->connection->method('update')->willReturnCallback(function (): int {
            $this->log[] = 'update';
            return 2;
        });
        $this->deliveries->method('recordMany')->willReturnCallback(function (): array {
            $this->log[] = 'record';
            return [1];
        });

        $this->writer()->change([4, 5], 2, 'admin');

        $this->assertSame(['begin', 'select', 'update', 'record', 'commit'], $this->log);
    }

    public function testItRollsBackAndTellsNobodyWhenADeliveryCannotBeWritten(): void
    {
        $events = $this->events = $this->createMock(SubmissionEvents::class);
        $deliveries = $this->deliveries = $this->createMock(SubmissionDeliveries::class);
        $this->connection->method('fetchAll')->willReturn($this->rows);
        $this->connection->method('update')->willReturn(2);
        $this->deliveries->method('recordMany')->willThrowException(new \RuntimeException('db gone'));
        $deliveries->expects($this->never())->method('wake');
        $events->expects($this->never())->method('statusChanged');

        try {
            $this->writer()->change([4, 5], 2, 'admin');
            $this->fail('Expected the failure to propagate.');
        } catch (\RuntimeException) {
            $this->assertSame(['begin', 'rollback'], $this->log);
        }
    }

    public function testItWakesTheWorkerAndFiresTheEventOnlyAfterCommit(): void
    {
        $deliveries = $this->deliveries = $this->createMock(SubmissionDeliveries::class);
        $this->connection->method('fetchAll')->willReturn($this->rows);
        $this->connection->method('update')->willReturn(2);
        $this->deliveries->method('recordMany')->willReturn([77]);
        $deliveries->expects($this->once())->method('wake')->with([77])
            ->willReturnCallback(function (): void {
                $this->log[] = 'wake';
            });
        $this->events->method('statusChanged')->willReturnCallback(function (): void {
            $this->log[] = 'event';
        });

        $this->writer()->change([4, 5], 2, 'admin');

        $this->assertSame(['begin', 'commit', 'wake', 'event', 'event'], $this->log);
    }

    public function testItFiresOneStatusChangedEventPerChangedRow(): void
    {
        $this->connection->method('fetchAll')->willReturn($this->rows);
        $this->connection->method('update')->willReturn(2);
        $this->deliveries->method('recordMany')->willReturn([]);
        $seen = [];
        $this->events->method('statusChanged')->willReturnCallback(
            function (int $id, int $form, int $old, int $new, string $source) use (&$seen): void {
                $seen[] = [$id, $form, $old, $new, $source];
            }
        );

        $this->writer()->change([4, 5], 2, 'admin');

        $this->assertSame([[4, 3, 0, 2, 'admin'], [5, 8, 1, 2, 'admin']], $seen);
    }

    public function testItNeitherRecordsNorFiresForARowAlreadyInTheTargetStatus(): void
    {
        $events = $this->events = $this->createMock(SubmissionEvents::class);
        $deliveries = $this->deliveries = $this->createMock(SubmissionDeliveries::class);
        $connection = $this->connection = $this->configureConnection($this->createMock(AdapterInterface::class));
        $this->rows = [['submission_id' => '4', 'form_id' => '3', 'store_id' => '2', 'status' => '2']];
        $this->connection->method('fetchAll')->willReturn($this->rows);
        $connection->expects($this->once())->method('update')->willReturn(0);
        $deliveries->expects($this->never())->method('recordMany');
        $events->expects($this->never())->method('statusChanged');

        $this->assertSame(1, $this->writer()->change([4], 2, 'admin'));
    }

    public function testItLocksTheRowsWithOneReadAndUpdatesThemByPrimaryKey(): void
    {
        $connection = $this->connection = $this->configureConnection($this->createMock(AdapterInterface::class));
        $connection->expects($this->once())->method('fetchAll')
            ->with($this->logicalAnd(
                $this->stringContains('submission_id IN (4,5)'),
                $this->stringEndsWith('FOR UPDATE')
            ))->willReturn($this->rows);
        $connection->expects($this->once())->method('update')->with(
            'furan_formbuilder_submission',
            $this->callback(static fn(array $bind): bool => $bind['status'] === 2 && isset($bind['updated_at'])),
            'submission_id IN (4,5)'
        )->willReturn(2);
        $this->deliveries->method('recordMany')->willReturn([]);

        $this->assertSame(2, $this->writer()->change([4, 5], 2, 'admin'));
    }

    public function testItRevokesGuestReplyLinksWhenClosing(): void
    {
        $connection = $this->connection = $this->configureConnection($this->createMock(AdapterInterface::class));
        $this->connection->method('fetchAll')->willReturn($this->rows);
        $connection->expects($this->once())->method('update')->with(
            $this->anything(),
            $this->callback(static fn(array $bind): bool => (string)$bind['thread_token_version'] === 'thread_token_version + 1'),
            $this->anything()
        )->willReturn(2);
        $this->deliveries->method('recordMany')->willReturn([]);

        $this->writer()->change([4, 5], 3, 'admin');
    }

    public function testItOnlyTouchesRowsInTheRequiredCurrentStatus(): void
    {
        $events = $this->events = $this->createMock(SubmissionEvents::class);
        $connection = $this->connection = $this->configureConnection($this->createMock(AdapterInterface::class));
        $this->rows = [['submission_id' => '4', 'form_id' => '3', 'store_id' => '2', 'status' => '2']];
        $connection->expects($this->once())->method('fetchAll')
            ->with($this->stringContains('AND status = 2'))->willReturn($this->rows);
        $this->deliveries->method('recordMany')->willReturn([]);
        $events->expects($this->once())->method('statusChanged')->with(4, 3, 2, 1, 'customer');

        $this->writer()->change([4], 1, 'customer', 2);
    }

    public function testItReportsNoRowsAndTouchesNothingForUnknownIds(): void
    {
        $deliveries = $this->deliveries = $this->createMock(SubmissionDeliveries::class);
        $connection = $this->connection = $this->configureConnection($this->createMock(AdapterInterface::class));
        $this->connection->method('fetchAll')->willReturn([]);
        $connection->expects($this->never())->method('update');
        $deliveries->expects($this->never())->method('wake');

        $this->assertSame(0, $this->writer()->change([4], 2, 'admin'));
        $this->assertSame(['begin', 'rollback'], $this->log);
    }
}
