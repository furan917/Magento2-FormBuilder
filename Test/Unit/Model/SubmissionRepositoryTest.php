<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model;

use Furan\FormBuilder\Api\Data\SubmissionSearchResultsInterfaceFactory;
use Furan\FormBuilder\Model\ResourceModel\Submission as SubmissionResource;
use Furan\FormBuilder\Model\ResourceModel\Submission\CollectionFactory;
use Furan\FormBuilder\Model\Search\KeysetCriteria;
use Furan\FormBuilder\Model\Submission;
use Furan\FormBuilder\Model\SubmissionFactory;
use Furan\FormBuilder\Model\Stats\Journal;
use Furan\FormBuilder\Model\SubmissionRepository;
use Furan\FormBuilder\Model\Webhook\SubmissionDeliveryCleaner;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SubmissionRepositoryTest extends TestCase
{
    private SubmissionResource&Stub $resource;
    private SubmissionFactory&Stub $factory;
    private Journal&Stub $journal;
    private AdapterInterface&Stub $connection;
    private Select&Stub $select;
    private SubmissionRepository $repository;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?Journal $journal = null, ?SubmissionResource $resource = null, ?Select $select = null, ?SubmissionDeliveryCleaner $cleaner = null): void
    {
        $this->resource = $resource ?? $this->createStub(SubmissionResource::class);
        $this->factory = $this->createStub(SubmissionFactory::class);
        $this->journal = $journal ?? $this->createStub(Journal::class);
        $this->connection = $this->createStub(AdapterInterface::class);
        $this->select = $select ?? $this->createStub(Select::class);
        $this->select->method('from')->willReturnSelf();
        $this->select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($this->select);
        $this->resource->method('getConnection')->willReturn($this->connection);
        $this->resource->method('getMainTable')->willReturn('furan_formbuilder_submission');
        $this->repository = new SubmissionRepository(
            $this->resource,
            $this->factory,
            $this->journal,
            $cleaner ?? $this->createStub(SubmissionDeliveryCleaner::class),
            $this->createStub(CollectionFactory::class),
            $this->createStub(SubmissionSearchResultsInterfaceFactory::class),
            new KeysetCriteria()
        );
    }

    private function lockedRow(): array
    {
        return ['submission_id' => 7, 'form_id' => 3, 'payload_json' => '[{"name":"a"}]',
            'created_at' => '2026-01-01 00:00:00', 'store_id' => 1, 'customer_group_id' => 0];
    }

    private function deletable(): Submission&Stub
    {
        $submission = $this->createStub(Submission::class);
        $submission->method('getId')->willReturn(7);
        return $submission;
    }

    public function testItClearsDeliveriesBeforeASingleSubmissionDeleteOpensItsTransaction(): void
    {
        $resource = $this->createMock(SubmissionResource::class);
        $cleaner = $this->createMock(SubmissionDeliveryCleaner::class);
        $this->fixture(resource: $resource, cleaner: $cleaner);
        $order = [];
        $cleaner->expects($this->once())->method('deleteFor')->with([7])
            ->willReturnCallback(function () use (&$order): void { $order[] = 'clean'; });
        $resource->expects($this->once())->method('beginTransaction')
            ->willReturnCallback(function () use (&$order) { $order[] = 'begin'; return $resource; });

        $this->repository->delete($this->deletable());

        $this->assertSame(['clean', 'begin'], $order);
    }

    public function testItWritesANegativeJournalRowWhenASubmissionIsDeleted(): void
    {
        $journal = $this->createMock(Journal::class);
        $this->fixture(journal: $journal);
        $row = $this->lockedRow();
        $this->connection->method('fetchRow')->willReturn($row);
        $journal->expects($this->once())->method('remove')->with([$row]);

        $this->repository->delete($this->deletable());
    }

    public function testItJournalsThePayloadReadUnderARowLock(): void
    {
        $journal = $this->createMock(Journal::class);
        $select = $this->createMock(Select::class);
        $this->fixture(journal: $journal, select: $select);
        $select->expects($this->once())->method('forUpdate')->with(true)->willReturnSelf();
        $row = $this->lockedRow();
        $row['payload_json'] = '[{"name":"locked"}]';
        $stale = $this->deletable();
        $stale->method('getPayload')->willReturn([['name' => 'stale']]);
        $this->connection->method('fetchRow')->willReturn($row);
        $journal->expects($this->once())->method('remove')->with([$row]);

        $this->repository->delete($stale);
    }

    public function testItJournalsNothingForARowAnotherTransactionAlreadyDeleted(): void
    {
        $journal = $this->createMock(Journal::class);
        $this->fixture(journal: $journal);
        $this->connection->method('fetchRow')->willReturn(false);
        $journal->expects($this->never())->method('remove');

        $this->assertTrue($this->repository->delete($this->deletable()));
    }

    public function testItWritesTheJournalInsideTheDeleteTransaction(): void
    {
        $calls = [];
        $this->connection->method('fetchRow')->willReturn($this->lockedRow());
        $this->resource->method('beginTransaction')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'begin';
            return $this->resource;
        });
        $this->journal->method('remove')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'journal';
        });
        $this->resource->method('delete')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'delete';
            return $this->resource;
        });
        $this->resource->method('commit')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'commit';
            return $this->resource;
        });

        $this->repository->delete($this->deletable());

        $this->assertSame(['begin', 'journal', 'delete', 'commit'], $calls);
    }

    public function testItRollsBackWhenTheJournalFails(): void
    {
        $resource = $this->createMock(SubmissionResource::class);
        $this->fixture(resource: $resource);
        $this->connection->method('fetchRow')->willReturn($this->lockedRow());
        $this->journal->method('remove')->willThrowException(new \RuntimeException('x'));
        $resource->expects($this->once())->method('rollBack');
        $resource->expects($this->never())->method('commit');

        $this->expectException(\Magento\Framework\Exception\CouldNotDeleteException::class);
        $this->repository->delete($this->deletable());
    }

    public function testItWrapsAnErrorRaisedWhileDeletingInACouldNotDeleteException(): void
    {
        $resource = $this->createMock(SubmissionResource::class);
        $this->fixture(resource: $resource);
        $this->connection->method('fetchRow')->willReturn($this->lockedRow());
        $this->journal->method('remove')->willThrowException(new \TypeError('x'));
        $resource->expects($this->once())->method('rollBack');

        $this->expectException(\Magento\Framework\Exception\CouldNotDeleteException::class);
        $this->repository->delete($this->deletable());
    }

    public function testItWritesNoJournalWhenASubmissionIsSaved(): void
    {
        $journal = $this->createMock(Journal::class);
        $this->fixture(journal: $journal);
        $journal->expects($this->never())->method('remove');
        $journal->expects($this->never())->method('add');
        $this->repository->save($this->createStub(Submission::class));
    }

    public function testItDeletesTheSubmissionThroughTheResource(): void
    {
        $resource = $this->createMock(SubmissionResource::class);
        $this->fixture(resource: $resource);
        $submission = $this->deletable();
        $this->connection->method('fetchRow')->willReturn($this->lockedRow());
        $resource->expects($this->once())->method('delete')->with($submission)->willReturnSelf();

        $this->assertTrue($this->repository->delete($submission));
    }

    public function testItThrowsNoSuchEntityWhenTheSubmissionDoesNotExist(): void
    {
        $this->factory->method('create')->willReturn($this->createStub(Submission::class));

        $this->expectException(NoSuchEntityException::class);
        $this->repository->getById(99);
    }

    public function testItFindsASubmissionByReferenceWithOneIndexedLookup(): void
    {
        $resource = $this->createMock(SubmissionResource::class);
        $this->fixture(resource: $resource);
        $bytes = hex2bin('018f3a5bc0de7abcb123456789abcdef');
        $submission = $this->createStub(Submission::class);
        $submission->method('getId')->willReturn(12);
        $this->factory->method('create')->willReturn($submission);
        $resource->expects($this->once())->method('load')
            ->with($submission, $bytes, 'reference')->willReturnSelf();

        $found = $this->repository->getByReference('018F3A5B-C0DE-7ABC-B123-456789ABCDEF');

        $this->assertSame($submission, $found);
    }

    public function testItThrowsNoSuchEntityForAMalformedReferenceWithoutQuerying(): void
    {
        $resource = $this->createMock(SubmissionResource::class);
        $this->fixture(resource: $resource);
        $this->factory->method('create')->willReturn($this->createStub(Submission::class));
        $resource->expects($this->never())->method('load');

        $this->expectException(NoSuchEntityException::class);
        $this->repository->getByReference('not-a-reference');
    }

    public function testItThrowsNoSuchEntityWhenNoSubmissionHasTheReference(): void
    {
        $this->factory->method('create')->willReturn($this->createStub(Submission::class));

        $this->expectException(NoSuchEntityException::class);
        $this->repository->getByReference('018f3a5b-c0de-7abc-b123-456789abcdef');
    }

    public function testItWrapsSaveFailuresInACouldNotSaveException(): void
    {
        $this->resource->method('save')->willThrowException(new \RuntimeException('db down'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->save($this->createStub(Submission::class));
    }
}
