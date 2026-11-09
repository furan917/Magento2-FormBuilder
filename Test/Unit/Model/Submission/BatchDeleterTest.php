<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Model\ResourceModel\Submission as SubmissionResource;
use Furan\FormBuilder\Model\Stats\Journal;
use Furan\FormBuilder\Model\Submission\BatchDeleter;
use Furan\FormBuilder\Model\Webhook\SubmissionDeliveryCleaner;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class BatchDeleterTest extends TestCase
{
    private SubmissionResource&Stub $resource;
    private AdapterInterface&Stub $connection;
    private Select&Stub $select;
    private Journal&Stub $journal;
    private SubmissionDeliveryCleaner&Stub $cleaner;
    private BatchDeleter $deleter;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?SubmissionDeliveryCleaner $cleaner = null, ?AdapterInterface $connection = null, ?Journal $journal = null, ?SubmissionResource $resource = null, ?Select $select = null): void
    {
        $this->resource = $resource ?? $this->createStub(SubmissionResource::class);
        $this->connection = $connection ?? $this->createStub(AdapterInterface::class);
        $this->select = $select ?? $this->createStub(Select::class);
        $this->select->method('from')->willReturnSelf();
        $this->select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($this->select);
        $this->resource->method('getConnection')->willReturn($this->connection);
        $this->resource->method('getMainTable')->willReturn('furan_formbuilder_submission');
        $this->journal = $journal ?? $this->createStub(Journal::class);
        $this->cleaner = $cleaner ?? $this->createStub(SubmissionDeliveryCleaner::class);
        $this->deleter = new BatchDeleter($this->resource, $this->journal, $this->cleaner);
    }

    private function row(int $id, int $formId = 1): array
    {
        return ['submission_id' => (string)$id, 'form_id' => (string)$formId, 'payload_json' => '[]',
            'created_at' => '2026-01-01 00:00:00', 'store_id' => '1', 'customer_group_id' => '0'];
    }

    public function testItDeletesABatchInOneTransaction(): void
    {
        $resource = $this->createMock(SubmissionResource::class);
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(resource: $resource, connection: $connection);
        $this->connection->method('fetchAll')->willReturn([$this->row(4), $this->row(5), $this->row(6)]);
        $resource->expects($this->once())->method('beginTransaction');
        $resource->expects($this->once())->method('commit');
        $resource->expects($this->never())->method('rollBack');
        $connection->expects($this->once())->method('delete')
            ->with('furan_formbuilder_submission', ['submission_id IN (?)' => [4, 5, 6]]);

        $this->assertSame(3, $this->deleter->deleteBatch([4, 5, 6]));
    }

    public function testItDeletesDeliveriesInBoundedBatchesBeforeDeletingSubmissions(): void
    {
        $cleaner = $this->createMock(SubmissionDeliveryCleaner::class);
        $this->fixture(cleaner: $cleaner);
        $calls = [];
        $cleaner->expects($this->once())->method('deleteFor')->with([4, 5])
            ->willReturnCallback(function () use (&$calls): void {
                $calls[] = 'deliveries';
            });
        $this->resource->method('beginTransaction')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'begin';
            return $this->resource;
        });
        $this->connection->method('fetchAll')->willReturn([$this->row(4)]);

        $this->deleter->deleteBatch([4, 5]);

        $this->assertSame(['deliveries', 'begin'], $calls);
    }

    public function testItMergesADeleteBatchIntoOneJournalRowPerForm(): void
    {
        $journal = $this->createMock(Journal::class);
        $this->fixture(journal: $journal);
        $rows = [$this->row(1, 1), $this->row(2, 1), $this->row(3, 2)];
        $this->connection->method('fetchAll')->willReturn($rows);
        $journal->expects($this->once())->method('remove')->with($rows);

        $this->deleter->deleteBatch([1, 2, 3]);
    }

    public function testItJournalsThePayloadReadUnderARowLockInsideTheDeleteTransaction(): void
    {
        $select = $this->createMock(Select::class);
        $this->fixture(select: $select);
        $calls = [];
        $select->expects($this->once())->method('forUpdate')->with(true)->willReturnSelf();
        $this->resource->method('beginTransaction')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'begin';
            return $this->resource;
        });
        $this->connection->method('fetchAll')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'select';
            return [$this->row(1)];
        });
        $this->journal->method('remove')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'journal';
        });
        $this->connection->method('delete')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'delete';
            return 1;
        });
        $this->resource->method('commit')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'commit';
            return $this->resource;
        });

        $this->deleter->deleteBatch([1]);

        $this->assertSame(['begin', 'select', 'journal', 'delete', 'commit'], $calls);
    }

    public function testItJournalsAndDeletesOnlyRowsStillPresent(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $journal = $this->createMock(Journal::class);
        $this->fixture(connection: $connection, journal: $journal);
        $this->connection->method('fetchAll')->willReturn([$this->row(2)]);
        $journal->expects($this->once())->method('remove')->with([$this->row(2)]);
        $connection->expects($this->once())->method('delete')
            ->with('furan_formbuilder_submission', ['submission_id IN (?)' => [2]]);

        $this->assertSame(1, $this->deleter->deleteBatch([1, 2]));
    }

    public function testItJournalsNothingWhenEveryRowIsAlreadyGone(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $journal = $this->createMock(Journal::class);
        $this->fixture(connection: $connection, journal: $journal);
        $this->connection->method('fetchAll')->willReturn([]);
        $journal->expects($this->never())->method('remove');
        $connection->expects($this->never())->method('delete');

        $this->assertSame(0, $this->deleter->deleteBatch([1, 2]));
    }

    public function testItRollsTheBatchBackWhenTheJournalFails(): void
    {
        $resource = $this->createMock(SubmissionResource::class);
        $this->fixture(resource: $resource);
        $this->connection->method('fetchAll')->willReturn([$this->row(1)]);
        $this->journal->method('remove')->willThrowException(new \RuntimeException('x'));
        $resource->expects($this->never())->method('commit');
        $resource->expects($this->once())->method('rollBack');

        $this->expectException(\RuntimeException::class);
        $this->deleter->deleteBatch([1]);
    }

    public function testAnEmptyBatchDoesNothing(): void
    {
        $resource = $this->createMock(SubmissionResource::class);
        $this->fixture(resource: $resource);
        $resource->expects($this->never())->method('beginTransaction');
        $this->assertSame(0, $this->deleter->deleteBatch([]));
    }
}
