<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Stats;

use Furan\FormBuilder\Model\Stats\AggregateMerger;
use Furan\FormBuilder\Model\Stats\Folder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FolderTest extends TestCase
{
    private const RED_KEY = '5e5a2b1e8c3d4f6a7b8c9d0e1f2a3b4c';

    private AdapterInterface&Stub $connection;
    private LockManagerInterface&Stub $lock;
    private LoggerInterface&Stub $logger;
    /** @var array<int, array{journal_id: int, form_id: int, deltas: string}> */
    private array $journal = [];
    /** @var int[] */
    private array $existingForms = [1];
    /** @var int[] */
    private array $staleForms = [];
    /** @var int[] */
    private array $lockedRebuilds = [];
    private ?int $deleteOverride = null;
    private bool $lockFree = true;
    /** @var array<int, array> */
    private array $calls = [];
    /** @var int[] remaining rows per staging delete call */
    private array $stagingChunks = [];
    private float $now = 0.0;
    private Folder $folder;

    private array $rebuildingForms = [];

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?LoggerInterface $logger = null): void
    {
        $this->connection = $this->createStub(AdapterInterface::class);
        $this->connection->method('fetchAll')->willReturnCallback(function (string $sql, array $bind = []): array {
            $this->calls[] = ['select', $sql, $bind];
            $rows = $this->journal;
            if (str_contains($sql, 'j.form_id = ?')) {
                $rows = array_filter($rows, static fn (array $r): bool => $r['form_id'] === $bind[0]);
            }
            usort($rows, static fn (array $a, array $b): int => $a['journal_id'] <=> $b['journal_id']);
            return array_slice(array_values($rows), 0, 500);
        });
        $this->connection->method('fetchCol')->willReturnCallback(function (string $sql, array $bind = []): array {
            $this->calls[] = ['fetchCol', $sql, $bind];
            if (str_contains($sql, 'rebuilding <> 0')) {
                return $this->rebuildingForms;
            }
            if (str_contains($sql, 'rebuilding = 1')) {
                return $this->staleForms;
            }
            return $this->existingForms;
        });
        $this->connection->method('fetchPairs')->willReturnCallback(fn(string $sql, array $bind = []): array => ['colour' => 11, 'gone' => 12]);
        $this->connection->method('fetchOne')->willReturnCallback(function (string $sql, array $bind = []): string {
            $this->calls[] = ['count', $sql, $bind];
            return (string)count(array_filter(
                $this->journal,
                static fn (array $r): bool => $r['form_id'] === $bind[0]
            ));
        });
        $this->connection->method('beginTransaction')->willReturnCallback(function (): AdapterInterface {
            $this->calls[] = ['begin'];
            return $this->connection;
        });
        $this->connection->method('commit')->willReturnCallback(function (): AdapterInterface {
            $this->calls[] = ['commit'];
            return $this->connection;
        });
        $this->connection->method('rollBack')->willReturnCallback(function (): AdapterInterface {
            $this->calls[] = ['rollback'];
            return $this->connection;
        });
        $this->connection->method('delete')->willReturnCallback(function (string $table, array $where): int {
            $ids = array_values(reset($where));
            $this->calls[] = ['delete', $table, $ids];
            if ($this->deleteOverride !== null) {
                return $this->deleteOverride;
            }
            $this->journal = array_filter($this->journal, static fn (array $r): bool => !in_array($r['journal_id'], $ids, true));
            return count($ids);
        });
        $this->connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $rows, array $update = []): int {
                $this->calls[] = ['upsert', $table, $rows, $update];
                return count($rows);
            }
        );
        $this->connection->method('update')->willReturnCallback(function (string $table, array $bind, $where): int {
            $this->calls[] = ['update', $table, $bind, $where];
            return 1;
        });
        $this->connection->method('query')->willReturnCallback(function (string $sql, array $bind = []) {
            $this->calls[] = ['query', $sql, $bind];
            $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
            $statement->method('rowCount')->willReturn(array_shift($this->stagingChunks) ?? 0);
            return $statement;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->lock = $this->createStub(LockManagerInterface::class);
        $this->lock->method('lock')->willReturnCallback(function (string $name): bool {
            $this->calls[] = ['lock', $name];
            return $this->lockFree;
        });
        $this->lock->method('unlock')->willReturnCallback(function (string $name): bool {
            $this->calls[] = ['unlock', $name];
            return true;
        });
        $this->lock->method('isLocked')->willReturnCallback(
            fn (string $name): bool => in_array($name, $this->lockedRebuilds, true)
        );
        $this->logger = $logger ?? $this->createStub(LoggerInterface::class);
        $this->folder = new Folder(
            $resource,
            $this->lock,
            new AggregateMerger(),
            new Json(),
            $this->logger,
            function (): float {
                $this->now += 10.0;
                return $this->now;
            }
        );
    }

    private function addJournal(int $id, int $formId = 1, string $field = 'colour', int $hits = 1): void
    {
        $this->journal[$id] = [
            'journal_id' => $id,
            'form_id' => $formId,
            'deltas' => (new Json())->serialize(['d' => [
                ['k' => $field, 'v' => self::RED_KEY, 'l' => 'red', 'p' => '2026-03-05', 's' => 2, 'g' => 1,
                    'h' => $hits, 'n' => 0, 't' => 0],
                ['k' => '__submissions', 'v' => null, 'l' => null, 'p' => '2026-03-05', 's' => 2, 'g' => 1,
                    'h' => $hits, 'n' => 0, 't' => 0],
            ]]),
        ];
    }

    /** @return array<int, array> */
    private function callsOf(string $type): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === $type));
    }

    private function sequence(): array
    {
        return array_map(static fn (array $c): string => $c[0], $this->calls);
    }

    public function testItFoldsEveryCommittedJournalRowEvenWhenIdsCommitOutOfOrder(): void
    {
        $this->addJournal(9);
        $this->addJournal(4);
        $this->addJournal(7);

        $this->folder->run(40);

        $this->assertSame([], $this->journal);
        $this->assertSame([4, 7, 9], $this->callsOf('delete')[0][2]);
        $this->assertStringNotContainsString('folded_through', $this->callsOf('select')[0][1]);
    }

    public function testItAppliesABatchAndDeletesExactlyItsJournalRowsInOneTransaction(): void
    {
        $this->addJournal(1);
        $this->addJournal(2);

        $this->folder->run(40);

        $seq = array_values(array_filter(
            $this->sequence(),
            static fn (string $t): bool => in_array($t, ['begin', 'delete', 'upsert', 'commit', 'rollback'], true)
        ));
        $first = array_slice($seq, 0, array_search('commit', $seq) + 1);
        $this->assertSame('begin', $first[0]);
        $this->assertSame('delete', $first[1]);
        $this->assertSame('commit', end($first));
        $this->assertNotContains('rollback', $seq);
        $this->assertNotEmpty(array_filter(
            $this->callsOf('fetchCol'),
            static fn (array $c): bool => str_contains($c[1], 'LOCK IN SHARE MODE')
        ));
        $tables = array_column($this->callsOf('upsert'), 1);
        $this->assertContains('furan_formbuilder_stat_day', $tables);
        $this->assertContains('furan_formbuilder_stat_value', $tables);
    }

    public function testItSkipsFoldingWhenAnotherFolderHoldsTheLock(): void
    {
        $this->addJournal(1);
        $this->lockFree = false;

        $this->folder->run(40);
        $pending = $this->folder->foldForm(1, 5);

        $this->assertCount(2, $this->callsOf('lock'));
        $this->assertSame([], $this->callsOf('select'));
        $this->assertSame([], $this->callsOf('begin'));
        $this->assertSame(1, $pending);
        $this->assertSame('furanform_stats_fold', $this->callsOf('lock')[0][1]);
    }

    public function testItLeavesJournalRowsOfAFormThatIsBeingRebuilt(): void
    {
        $this->rebuildingForms = [7];
        $this->folder->run(40);

        [, $sql, $bind] = $this->callsOf('select')[0];
        $this->assertStringContainsString('j.form_id NOT IN', $sql);
        $this->assertSame([7], $bind);
        $this->assertStringContainsString('ORDER BY j.journal_id', $sql);
        $this->assertStringContainsString('LIMIT 500', $sql);
    }

    public function testItWalksTheJournalPrimaryKeyWithoutJoiningStateRows(): void
    {
        $this->folder->run(40);

        $sql = $this->callsOf('select')[0][1];
        $this->assertStringNotContainsString('JOIN', $sql);
        $this->assertStringNotContainsString('NOT IN', $sql);
        $this->assertStringContainsString('ORDER BY j.journal_id LIMIT 500', $sql);
    }

    public function testItAddsHitsCountsAndTotalsToExistingAggregateRowsInsteadOfOverwritingThem(): void
    {
        $this->addJournal(1);
        $this->journal[1]['deltas'] = (new Json())->serialize(['d' => [
            ['k' => 'colour', 'v' => null, 'l' => null, 'p' => '2026-03-05', 's' => 2, 'g' => 1,
                'h' => 0, 'n' => 1, 't' => 4.5],
        ]]);

        $this->folder->run(40);

        $byTable = [];
        foreach ($this->callsOf('upsert') as $call) {
            $byTable[$call[1]] = $call;
        }
        $this->assertEquals(
            ['n' => new \Zend_Db_Expr('n + VALUES(n)'), 'total' => new \Zend_Db_Expr('total + VALUES(total)')],
            $byTable['furan_formbuilder_stat_number'][3]
        );
        $this->assertSame(11, $byTable['furan_formbuilder_stat_number'][2][0]['field_id']);
        $this->assertArrayHasKey('is_rollup', $byTable['furan_formbuilder_stat_number'][2][0]);
        $this->assertCount(4, $byTable['furan_formbuilder_stat_number'][2]);
    }

    public function testItAddsHitsAndSubmissionsToExistingAggregateRowsInsteadOfOverwritingThem(): void
    {
        $this->addJournal(1);

        $this->folder->run(40);

        $byTable = [];
        foreach ($this->callsOf('upsert') as $call) {
            $byTable[$call[1]] = $call;
        }
        $this->assertEquals(['hits' => new \Zend_Db_Expr('hits + VALUES(hits)')], $byTable['furan_formbuilder_stat_value'][3]);
        $this->assertEquals(
            ['submissions' => new \Zend_Db_Expr('submissions + VALUES(submissions)')],
            $byTable['furan_formbuilder_stat_day'][3]
        );
        $this->assertSame(1, $byTable['furan_formbuilder_stat_day'][2][0]['form_id']);
        $this->assertSame(['label'], $byTable['furan_formbuilder_stat_label'][3]);
        $this->assertSame(hex2bin(self::RED_KEY), $byTable['furan_formbuilder_stat_label'][2][0]['value_key']);
    }

    public function testItRollsBackABatchWhoseJournalRowsWereAlreadyRemovedByAnotherFolder(): void
    {
        $this->addJournal(1);
        $this->addJournal(2);
        $this->deleteOverride = 1;

        $this->folder->run(40);

        $this->assertSame([], $this->callsOf('upsert'));
        $this->assertSame([], $this->callsOf('commit'));
        $this->assertCount(1, $this->callsOf('rollback'));
    }

    public function testItDropsTheDeltasOfADeletedFormAndAppliesTheRestOfTheBatch(): void
    {
        $this->addJournal(1, 1);
        $this->addJournal(2, 5);
        $this->existingForms = [1];

        $this->folder->run(40);

        $this->assertSame([1], $this->callsOf('delete')[0][2]);
        foreach ($this->callsOf('upsert') as $call) {
            if ($call[1] === 'furan_formbuilder_stat_day') {
                $this->assertSame([1], array_unique(array_column($call[2], 'form_id')));
            }
        }
        $this->assertNotEmpty($this->callsOf('commit'));
    }

    public function testItLeavesTheJournalRowsInPlaceWhenTheBatchFails(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->fixture(logger: $logger);
        $this->addJournal(1);
        $failing = $this->createMock(AdapterInterface::class);
        $failing->method('fetchAll')->willReturn([$this->journal[1]]);
        $failing->method('fetchCol')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'rebuilding') ? [] : [1]
        );
        $failing->method('delete')->willThrowException(new \RuntimeException('Deadlock 1213'));
        $failing->expects($this->once())->method('rollBack');
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($failing);
        $resource->method('getTableName')->willReturnArgument(0);
        $folder = new Folder($resource, $this->lock, new AggregateMerger(), new Json(), $this->logger);
        $logger->expects($this->atLeastOnce())->method('error');

        $folder->run(40);
    }

    public function testItClearsARebuildingFlagWhoseRebuildLockIsNoLongerHeld(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->fixture(logger: $logger);
        $this->staleForms = [3, 4];
        $this->lockedRebuilds = ['furanform_stats_rebuild_4'];
        $logger->expects($this->once())->method('warning');

        $this->folder->run(40);

        $updates = $this->callsOf('update');
        $this->assertCount(1, $updates);
        $this->assertSame('furan_formbuilder_stat_state', $updates[0][1]);
        $this->assertSame(['rebuilding' => 0], $updates[0][2]);
        $this->assertSame(3, $updates[0][3]['form_id = ?']);
    }

    public function testItKeepsFoldingBatchesUntilTheJournalIsEmptyOrTheTimeBudgetIsSpent(): void
    {
        for ($i = 1; $i <= 1200; $i++) {
            $this->addJournal($i);
        }
        $this->folder->run(1000);
        $this->assertSame([], $this->journal);
        $this->assertCount(3, $this->callsOf('commit'));

        $this->journal = [];
        $this->calls = [];
        for ($i = 1; $i <= 1200; $i++) {
            $this->addJournal($i);
        }
        $this->now = 0.0;
        $this->folder->run(25);
        $this->assertLessThan(3, count($this->callsOf('commit')));
        $this->assertNotSame([], $this->journal);
    }

    public function testItDeletesACrashedRebuildsStagingRowsWhenItClearsTheStaleFlag(): void
    {
        $this->staleForms = [3];
        $this->stagingChunks = [10000, 5];

        $this->folder->run(1000);

        $deletes = $this->callsOf('query');
        $this->assertStringContainsString('furan_formbuilder_stat_stage_day', $deletes[0][1]);
        $this->assertStringContainsString('WHERE form_id = ?', $deletes[0][1]);
        $this->assertStringContainsString('LIMIT 10000', $deletes[0][1]);
        $this->assertSame([3], $deletes[0][2]);
        $this->assertStringContainsString('furan_formbuilder_stat_stage_day', $deletes[1][1]);
        $this->assertStringContainsString('furan_formbuilder_stat_stage_value', $deletes[2][1]);
        $this->assertCount(6, $deletes);
    }

    public function testItCapsThePendingCountReturnedByFoldForm(): void
    {
        $this->addJournal(1);
        $pending = $this->folder->foldForm(1, 5);

        $this->assertSame(0, $pending);
        $this->assertStringContainsString('LIMIT 10001', $this->callsOf('count')[0][1]);
        $this->assertStringContainsString('j.form_id = ?', $this->callsOf('select')[0][1]);
    }
}
