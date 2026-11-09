<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Form;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Model\Form\Purger;
use Furan\FormBuilder\Model\Webhook\SubmissionDeliveryCleaner;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PurgerTest extends TestCase
{
    private AdapterInterface&Stub $connection;
    private FormRepositoryInterface&Stub $repository;
    private LockManagerInterface&Stub $locks;
    /** @var array<int, array{string, array}> */
    private array $queries = [];
    /** @var array<string, int[]> rows each DELETE statement removes, by table, consumed in order */
    private array $deletes = [];
    private float $now = 0.0;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?AdapterInterface $connection = null, ?LockManagerInterface $locks = null, ?FormRepositoryInterface $repository = null): void
    {
        $this->connection = $connection ?? $this->createStub(AdapterInterface::class);
        $this->connection->method('query')->willReturnCallback(function (string $sql, array $bind = []) {
            $this->queries[] = [$sql, $bind];
            $this->now += 1;
            $removed = 0;
            foreach ($this->deletes as $table => &$counts) {
                if (str_contains($sql, 'DELETE FROM ' . $table . ' ')) {
                    $removed = array_shift($counts) ?? 0;
                }
            }
            $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
            $statement->method('rowCount')->willReturn($removed);
            return $statement;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->repository = $repository ?? $this->createStub(FormRepositoryInterface::class);
        $this->locks = $locks ?? $this->createStub(LockManagerInterface::class);
        $this->cleaner = $this->createStub(SubmissionDeliveryCleaner::class);
        $this->purger = new Purger(
            $resource,
            $this->repository,
            $this->locks,
            $this->createStub(LoggerInterface::class),
            $this->createStub(\Furan\FormBuilder\Model\ResourceModel\Submission\KeysetIndex::class),
            $this->cleaner,
            fn (): float => $this->now
        );
    }

    private SubmissionDeliveryCleaner&Stub $cleaner;
    private Purger $purger;

    public function testItProbesForSubmissionsWithOneIndexSeekInsteadOfCounting(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $connection->expects($this->once())->method('fetchOne')
            ->with($this->callback(static fn (string $sql): bool => str_contains($sql, 'WHERE form_id = ? LIMIT 1')
                && !str_contains($sql, 'COUNT')), [7])
            ->willReturn('1');

        $this->assertTrue($this->purger->hasSubmissions(7));
    }

    public function testItCountsAtMostOneRowPastTheInlineLimitToDecideOnABackgroundPurge(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $connection->method('fetchOne')
            ->with($this->stringContains('LIMIT ' . (Purger::INLINE_LIMIT + 1) . ') t'), [7])
            ->willReturn((string)(Purger::INLINE_LIMIT + 1));

        $this->assertTrue($this->purger->isLarge(7));
    }

    public function testItDisablesAndFlagsAFormForTheBackgroundPurge(): void
    {
        $repository = $this->createMock(FormRepositoryInterface::class);
        $this->fixture(repository: $repository);
        $form = $this->createMock(Form::class);
        $form->expects($this->once())->method('setIsActive')->with(false)->willReturnSelf();
        $form->expects($this->once())->method('setData')
            ->with('purge_requested_at', $this->matchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/'))
            ->willReturnSelf();
        $repository->expects($this->once())->method('save')->with($form);

        $this->purger->schedule($form);
    }

    /** @param array<int, int[]> $pages ids returned by successive keyset selects */
    private function idPages(array $pages): void
    {
        $this->connection->method('fetchCol')->willReturnCallback(function (string $sql, array $bind = []) use (&$pages) {
            if (str_contains($sql, 'purge_requested_at')) {
                return ['7'];
            }
            $this->queries[] = [$sql, $bind];
            $this->now += 1;
            return array_shift($pages) ?? [];
        });
    }

    public function testItDeletesAFlaggedFormsRowsInSmallAutocommitBatchesThenTheFormItself(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $repository = $this->createMock(FormRepositoryInterface::class);
        $locks = $this->createMock(LockManagerInterface::class);
        $this->fixture(connection: $connection, repository: $repository, locks: $locks);
        $locks->method('lock')->with(Purger::LOCK_NAME, 0)->willReturn(true);
        $locks->expects($this->once())->method('unlock')->with(Purger::LOCK_NAME);
        $this->idPages([range(1, Purger::BATCH_SIZE), [9001, 9002]]);
        $form = $this->createStub(Form::class);
        $repository->method('getById')->with(7)->willReturn($form);
        $repository->expects($this->once())->method('delete')->with($form);
        $connection->expects($this->never())->method('beginTransaction');

        $this->purger->run(60);

        $selects = array_values(array_filter($this->queries, static fn (array $q): bool => str_starts_with($q[0], 'SELECT')));
        $this->assertSame([7, 0], $selects[0][1]);
        $this->assertSame([7, Purger::BATCH_SIZE], $selects[1][1]);
        $this->assertStringContainsString('WHERE form_id = ? AND submission_id > ? ORDER BY submission_id LIMIT', $selects[0][0]);
        $deletes = array_values(array_filter(
            $this->queries,
            static fn (array $q): bool => str_starts_with($q[0], 'DELETE FROM furan_formbuilder_submission ')
        ));
        $this->assertSame('DELETE FROM furan_formbuilder_submission WHERE submission_id IN (9001,9002)', $deletes[1][0]);
        $chunked = implode("\n", array_column($this->queries, 0));
        foreach (['draft', 'respondent', 'stat_journal', 'stat_day', 'stat_value'] as $table) {
            $this->assertStringContainsString('DELETE FROM furan_formbuilder_' . $table . ' WHERE', $chunked);
        }
    }

    public function testItDeletesDeliveriesInBoundedBatchesBeforeAFormPurgeDeletesSubmissions(): void
    {
        $this->locks->method('lock')->willReturn(true);
        $this->idPages([[11, 12]]);
        $order = [];
        $this->cleaner->method('deleteFor')->willReturnCallback(function (array $ids) use (&$order): void {
            $order[] = 'deliveries ' . implode(',', $ids);
        });
        $this->repository->method('getById')->willReturn($this->createStub(Form::class));

        $this->purger->run(60);

        $this->assertSame(['deliveries 11,12'], $order);
        $first = array_values(array_filter($this->queries, static fn (array $q): bool => str_starts_with($q[0], 'DELETE FROM furan_formbuilder_submission ')));
        $this->assertSame('DELETE FROM furan_formbuilder_submission WHERE submission_id IN (11,12)', $first[0][0]);
    }

    public function testItDeletesASmallFormsDeliveriesInBoundedBatchesBeforeDeletingTheForm(): void
    {
        $form = $this->createStub(Form::class);
        $form->method('getId')->willReturn(1);
        $this->connection->method('fetchOne')->willReturn('3');
        $this->idPages([[21, 22]]);
        $calls = [];
        $this->cleaner->method('deleteFor')->willReturnCallback(function (array $ids) use (&$calls): void {
            $calls[] = 'deliveries ' . implode(',', $ids);
        });
        $this->repository->method('delete')->willReturnCallback(function () use (&$calls): bool {
            $calls[] = 'form';
            return true;
        });

        $this->assertTrue($this->purger->deleteOrSchedule($form));

        $this->assertSame(['deliveries 21,22', 'form'], $calls);
        $deletes = array_filter($this->queries, static fn (array $q): bool => str_starts_with($q[0], 'DELETE FROM furan_formbuilder_submission '));
        $this->assertCount(1, $deletes, 'submissions are gone before the form row is deleted');
    }

    public function testItStopsAtTheBudgetAndLeavesTheFormForTheNextRun(): void
    {
        $repository = $this->createMock(FormRepositoryInterface::class);
        $this->fixture(repository: $repository);
        $this->locks->method('lock')->willReturn(true);
        $this->idPages(array_fill(0, 100, range(1, Purger::BATCH_SIZE)));
        $repository->expects($this->never())->method('delete');

        $this->purger->run(5);

        $this->assertLessThanOrEqual(6, count($this->queries));
    }

    public function testItDoesNothingWhileAnotherNodeIsPurging(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $this->locks->method('lock')->willReturn(false);
        $connection->expects($this->never())->method('fetchCol');

        $this->purger->run(60);
    }

    public function testItDeletesASmallFormInlineAndSchedulesALargeOne(): void
    {
        $repository = $this->createMock(FormRepositoryInterface::class);
        $this->fixture(repository: $repository);
        $small = $this->createStub(Form::class);
        $small->method('getId')->willReturn(1);
        $large = $this->createStub(Form::class);
        $large->method('getId')->willReturn(2);
        $large->method('setIsActive')->willReturnSelf();
        $large->method('setData')->willReturnSelf();
        $this->connection->method('fetchCol')->willReturn([]);
        $this->connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql, array $bind): string => $bind === [2] ? (string)(Purger::INLINE_LIMIT + 1) : '3'
        );
        $repository->expects($this->once())->method('delete')->with($small);
        $repository->expects($this->once())->method('save')->with($large);

        $this->assertTrue($this->purger->deleteOrSchedule($small));
        $this->assertFalse($this->purger->deleteOrSchedule($large));
    }
}
