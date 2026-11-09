<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Stats;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Model\Stats\AggregateMerger;
use Furan\FormBuilder\Model\Stats\Bucket;
use Furan\FormBuilder\Model\Stats\DeltaCalculator;
use Furan\FormBuilder\Model\Stats\Rebuilder;
use Furan\FormBuilder\Model\Stats\SeriesRegistry;
use Furan\FormBuilder\Model\Stats\SeriesSpec;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Model\ResourceModel\Type\Db\ConnectionFactoryInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RebuilderTest extends TestCase
{
    private AdapterInterface&Stub $main;
    private AdapterInterface&Stub $stage;
    private SeriesRegistry&Stub $registry;
    private LockManagerInterface&Stub $lock;
    private LoggerInterface&Stub $logger;
    /** @var array<int, array> ordered log: [source, type, ...] */
    private array $calls = [];
    /** @var array<int, array> */
    private array $submissions = [];
    /** @var int[] */
    private array $journalIds = [];
    private int $level = 0;
    private int $stagedJournal = 0;
    private ?int $journalDeleteOverride = null;
    private ?string $failOn = null;
    private bool $rebuildLockBusy = false;
    private int $foldLockMisses = 0;
    private bool $foldFirstDone = false;
    private float $now = 0.0;
    private int $factoryCalls = 0;
    private Rebuilder $rebuilder;

    protected function setUp(): void
    {
        $this->main = $this->createStub(AdapterInterface::class);
        $this->stage = $this->createStub(AdapterInterface::class);
        $this->wireMain();
        $this->wireStage();

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->main);
        $resource->method('getTableName')->willReturnArgument(0);

        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('get')->willReturn(['host' => 'db']);
        $factory = $this->createStub(ConnectionFactoryInterface::class);
        $factory->method('create')->willReturnCallback(function (array $config): AdapterInterface {
            $this->factoryCalls++;
            return $this->stage;
        });

        $this->lock = $this->createStub(LockManagerInterface::class);
        $this->lock->method('lock')->willReturnCallback(function (string $name, int $wait = 0): bool {
            $this->calls[] = ['lock', 'lock', $name, $wait];
            if (str_starts_with($name, 'furanform_stats_rebuild_')) {
                return !$this->rebuildLockBusy;
            }
            if (!$this->foldFirstDone) {
                $this->foldFirstDone = true;
                return true;
            }
            if ($this->foldLockMisses > 0) {
                $this->foldLockMisses--;
                $this->now += 60.0;
                return false;
            }
            return true;
        });
        $this->lock->method('unlock')->willReturnCallback(function (string $name): bool {
            $this->calls[] = ['lock', 'unlock', $name];
            return true;
        });

        $form = $this->createStub(FormInterface::class);
        $form->method('getSchema')->willReturn(['pages' => [['items' => [
            ['kind' => 'section', 'items' => [['kind' => 'field', 'name' => 'colour', 'type' => 'select']]],
            ['kind' => 'content', 'type' => 'heading'],
        ]]]]);
        $forms = $this->createStub(FormRepositoryInterface::class);
        $forms->method('getById')->willReturn($form);

        $this->registry = $this->createStub(SeriesRegistry::class);
        $this->registry->method('specsFor')->willReturnCallback(function (int $formId, array $fields): array {
            $this->calls[] = ['registry', 'specsFor', $formId, array_column($fields, 'name')];
            return [];
        });
        $this->registry->method('resetSpecs')->willReturnCallback(function (int $formId, array $fields): int {
            $this->calls[] = ['registry', 'resetSpecs', $formId, array_column($fields, 'name')];
            if ($this->failOn === 'reset') {
                throw new \RuntimeException('reset failed');
            }
            return 1;
        });
        $this->registry->method('load')->willReturnCallback(function (int $formId): array {
            $this->calls[] = ['main', 'load', $formId];
            return ['colour' => ['kind' => SeriesSpec::KIND_CHOICE, 'bucket_step' => null]];
        });

        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturn('UTC');
        $this->logger = $this->createStub(LoggerInterface::class);

        $this->rebuilder = new Rebuilder(
            $resource,
            $factory,
            $deployment,
            $this->lock,
            $forms,
            $this->registry,
            new DeltaCalculator(new Bucket()),
            new AggregateMerger(),
            new Json(),
            $scope,
            $this->logger,
            $this->createStub(\Furan\FormBuilder\Model\ResourceModel\Submission\KeysetIndex::class),
            fn(): float => $this->now
        );
    }

    private function wireMain(): void
    {
        $this->main->method('getTransactionLevel')->willReturnCallback(fn (): int => $this->level);
        $this->main->method('beginTransaction')->willReturnCallback(function (): AdapterInterface {
            $this->level++;
            $this->calls[] = ['main', 'begin'];
            return $this->main;
        });
        $this->main->method('commit')->willReturnCallback(function (): AdapterInterface {
            $this->level--;
            $this->calls[] = ['main', 'commit'];
            return $this->main;
        });
        $this->main->method('rollBack')->willReturnCallback(function (): AdapterInterface {
            $this->level--;
            $this->calls[] = ['main', 'rollback'];
            return $this->main;
        });
        $this->main->method('fetchOne')->willReturnCallback(function (string $sql, array $bind = []): string {
            $this->calls[] = ['main', 'fetchOne', $sql, $bind];
            return '7';
        });
        $this->main->method('fetchPairs')->willReturnCallback(function (string $sql, array $bind = []): array {
            $this->calls[] = ['main', 'fetchPairs', $sql, $bind];
            return ['colour' => '11'];
        });
        $this->main->method('fetchAll')->willReturnCallback(function (string $sql, array $bind = []): array {
            $this->calls[] = ['main', 'scan', $sql, $bind];
            if ($this->failOn === 'scan') {
                throw new \RuntimeException('scan failed');
            }
            $rows = array_filter(
                $this->submissions,
                static fn (array $r): bool => $r['submission_id'] > $bind[1]
            );
            return array_slice(array_values($rows), 0, 500);
        });
        $this->main->method('fetchCol')->willReturnCallback(function (string $sql, array $bind = []): array {
            $this->calls[] = ['main', 'journalScan', $sql, $bind];
            $ids = array_filter($this->journalIds, static fn (int $id): bool => $id > $bind[1]);
            return array_slice(array_values($ids), 0, 1000);
        });
        $this->main->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $rows, array $update = []): int {
                $this->calls[] = ['main', 'upsert', $table, $rows, $update];
                return count($rows);
            }
        );
        $this->main->method('update')->willReturnCallback(function (string $table, array $bind, $where): int {
            $this->calls[] = ['main', 'update', $table, $bind, $where];
            return 1;
        });
        $this->main->method('delete')->willReturnCallback(function (string $table, $where): int {
            $this->calls[] = ['main', 'delete', $table, $where];
            return 1;
        });
        $this->main->method('query')->willReturnCallback(function (string $sql, array $bind = []) {
            $this->calls[] = ['main', 'query', $sql, $bind];
            $count = 0;
            if (str_starts_with($sql, 'DELETE j FROM')) {
                $count = $this->journalDeleteOverride ?? $this->stagedJournal;
            }
            $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
            $statement->method('rowCount')->willReturn($count);
            return $statement;
        });
    }

    private function wireStage(): void
    {
        $this->stage->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $rows, array $update = []): int {
                $this->calls[] = ['stage', 'upsert', $table, $rows, $update];
                return count($rows);
            }
        );
        $this->stage->method('insertMultiple')->willReturnCallback(function (string $table, array $rows): int {
            $this->calls[] = ['stage', 'insert', $table, $rows];
            if (str_ends_with($table, 'stat_stage_journal')) {
                $this->stagedJournal += count($rows);
            }
            return count($rows);
        });
    }

    private function addSubmissions(int $count, string $value = 'red'): void
    {
        $start = count($this->submissions);
        for ($i = 1; $i <= $count; $i++) {
            $this->submissions[] = [
                'submission_id' => $start + $i,
                'payload_json' => json_encode([
                    ['name' => 'colour', 'value' => $value],
                    ['name' => 'unspecced', 'value' => 'x'],
                ]),
                'created_at' => '2026-03-05 10:00:00',
                'store_id' => '2',
                'customer_group_id' => '1',
            ];
        }
    }

    /** @return array<int, array> */
    private function callsOf(string $source, string $type): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $c): bool => $c[0] === $source && $c[1] === $type
        ));
    }

    /** @return string[] */
    private function tags(): array
    {
        return array_map(static fn (array $c): string => $c[0] . ':' . $c[1], $this->calls);
    }

    private function stagedRows(string $table): array
    {
        $rows = [];
        foreach ($this->callsOf('stage', 'upsert') as $call) {
            if ($call[2] === $table) {
                array_push($rows, ...$call[3]);
            }
        }
        return $rows;
    }

    public function testItRebuildsAFormsAggregatesFromItsSubmissions(): void
    {
        $this->addSubmissions(3);

        $this->rebuilder->rebuild(1);

        $days = $this->stagedRows('furan_formbuilder_stat_stage_day');
        $detailDay = array_values(array_filter(
            $days,
            static fn (array $r): bool => $r['grain'] === 0 && $r['is_rollup'] === 0
        ));
        $this->assertSame(3, $detailDay[0]['submissions']);
        $this->assertSame(1, $detailDay[0]['form_id']);
        $this->assertSame(7, $detailDay[0]['rebuild_id']);
        $values = $this->stagedRows('furan_formbuilder_stat_stage_value');
        $this->assertSame(11, $values[0]['field_id']);
        $this->assertSame(3, $values[0]['hits']);
        $this->assertCount(4, $values);
        $this->assertSame('red', $this->stagedRows('furan_formbuilder_stat_stage_label')[0]['label']);
    }

    public function testItScansSubmissionsByLastSeenIdInBatches(): void
    {
        $this->addSubmissions(1200);

        $this->rebuilder->rebuild(1);

        $scans = $this->callsOf('main', 'scan');
        $this->assertCount(3, $scans);
        $this->assertSame([1, 0], $scans[0][3]);
        $this->assertSame([1, 500], $scans[1][3]);
        $this->assertSame([1, 1000], $scans[2][3]);
        $this->assertStringContainsString('ORDER BY submission_id LIMIT 500', $scans[0][2]);
        $this->assertStringNotContainsString('OFFSET', $scans[0][2]);
        $this->assertStringNotContainsString('FOR UPDATE', $scans[0][2]);
        $this->assertStringNotContainsString('LOCK IN SHARE MODE', $scans[0][2]);
    }

    public function testItDeletesExactlyTheJournalRowsVisibleInItsSnapshotAndLeavesLaterOnesForTheFolder(): void
    {
        $this->addSubmissions(1);
        $this->journalIds = range(1, 2500);

        $this->rebuilder->rebuild(1);

        $scans = $this->callsOf('main', 'journalScan');
        $this->assertSame([1, 0], $scans[0][3]);
        $this->assertSame([1, 1000], $scans[1][3]);
        $this->assertSame([1, 2000], $scans[2][3]);
        $staged = array_merge(...array_map(
            static fn (array $c): array => array_column($c[3], 'journal_id'),
            array_filter($this->callsOf('stage', 'insert'), static fn (array $c): bool => str_ends_with($c[2], 'stat_stage_journal'))
        ));
        $this->assertSame(range(1, 2500), $staged);
        $deletes = array_filter(
            $this->callsOf('main', 'query'),
            static fn (array $c): bool => str_starts_with($c[2], 'DELETE j FROM')
        );
        $this->assertCount(1, $deletes);
        $sql = array_values($deletes)[0][2];
        $this->assertStringContainsString('JOIN furan_formbuilder_stat_stage_journal s', $sql);
        $this->assertSame([1, 7], array_values($deletes)[0][3]);
    }

    public function testItFlagsTheFormAsRebuildingWhileItRunsAndClearsTheFlagOnFailure(): void
    {
        $this->addSubmissions(1);
        $this->failOn = 'scan';

        try {
            $this->rebuilder->rebuild(1);
            $this->fail('Expected the failure to be rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('scan failed', $e->getMessage());
        }

        $upsert = $this->callsOf('main', 'upsert')[0];
        $this->assertSame('furan_formbuilder_stat_state', $upsert[2]);
        $this->assertSame(1, $upsert[3][0]['rebuilding']);
        $updates = $this->callsOf('main', 'update');
        $this->assertSame(['rebuilding' => 0], end($updates)[3]);
        $this->assertSame(0, $this->level);
        $this->assertSame(0, count($this->callsOf('main', 'delete')));
        $this->assertContains('main:rollback', $this->tags());
        $this->assertSame(
            ['furanform_stats_rebuild_1'],
            array_column(array_filter($this->callsOf('lock', 'unlock'), static fn (array $c): bool => $c[2] === 'furanform_stats_rebuild_1'), 2)
        );
    }

    public function testItKeepsExistingSeriesSpecs(): void
    {
        $this->addSubmissions(1);

        $this->rebuilder->rebuild(1);

        $this->assertSame([[ 'registry', 'specsFor', 1, ['colour']]], $this->callsOf('registry', 'specsFor'));
        $tables = array_column(array_merge($this->callsOf('main', 'update'), $this->callsOf('main', 'delete')), 2);
        $this->assertNotContains('furan_formbuilder_stat_field', $tables);
        // Payload keys without a spec ("unspecced") never reach the staging rows.
        $this->assertCount(4, $this->stagedRows('furan_formbuilder_stat_stage_value'));
        $tags = $this->tags();
        $this->assertLessThan(array_search('main:begin', $tags, true), array_search('registry:specsFor', $tags, true));
    }

    public function testItForcesRepeatableReadBeforeOpeningTheSnapshotTransaction(): void
    {
        $this->addSubmissions(1);

        $this->rebuilder->rebuild(1);

        $tags = $this->tags();
        $begin = array_search('main:begin', $tags, true);
        $this->assertSame('main:query', $tags[$begin - 1]);
        $this->assertSame('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', $this->calls[$begin - 1][2]);
        $this->assertSame('main:load', $tags[$begin + 1]);
        $snapshot = array_slice($tags, $begin, array_search('main:commit', $tags, true) - $begin);
        $this->assertSame([], array_values(array_intersect($snapshot, [
            'main:upsert', 'main:update', 'main:delete', 'main:query',
        ])));
    }

    public function testItRefusesToStartWhileAnotherRebuildOfTheSameFormHoldsItsLock(): void
    {
        $this->rebuildLockBusy = true;

        try {
            $this->rebuilder->rebuild(1);
            $this->fail('Expected a refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already running', $e->getMessage());
        }

        $this->assertSame(['lock:lock'], $this->tags());
        $this->assertSame(['lock', 'lock', 'furanform_stats_rebuild_1', 0], $this->calls[0]);
    }

    public function testItClearsTheNeedsRebuildFlagWhenTheRebuildCommits(): void
    {
        $this->addSubmissions(1);

        $this->rebuilder->rebuild(1);

        $final = array_values(array_filter(
            $this->callsOf('main', 'update'),
            static fn (array $c): bool => $c[3] === ['rebuilding' => 0, 'needs_rebuild' => 0]
        ));
        $this->assertCount(1, $final);
        $this->assertSame('furan_formbuilder_stat_state', $final[0][2]);
        $commit = array_keys(array_filter($this->tags(), static fn (string $t): bool => $t === 'main:commit'));
        $this->assertLessThan(end($commit), array_search($final[0], $this->calls, true));
    }

    public function testItStagesEachBatchesTotalsKeyedByFormAndRebuildIdInsteadOfHoldingThemInMemory(): void
    {
        $this->addSubmissions(1200);

        $this->rebuilder->rebuild(1);

        $this->assertSame(1, $this->factoryCalls);
        $upserts = array_filter(
            $this->callsOf('stage', 'upsert'),
            static fn (array $c): bool => $c[2] === 'furan_formbuilder_stat_stage_day'
        );
        $this->assertGreaterThanOrEqual(3, count($upserts));
        $first = array_values($upserts)[0];
        $this->assertSame(['form_id' => 1, 'rebuild_id' => 7], array_intersect_key($first[3][0], ['form_id' => 1, 'rebuild_id' => 1]));
        $this->assertSame(
            'submissions + VALUES(submissions)',
            (string)$first[4]['submissions']
        );
        $valueUpsert = array_values(array_filter(
            $this->callsOf('stage', 'upsert'),
            static fn (array $c): bool => $c[2] === 'furan_formbuilder_stat_stage_value'
        ))[0];
        $this->assertSame('hits + VALUES(hits)', (string)$valueUpsert[4]['hits']);
        $this->assertSame(16, strlen($valueUpsert[3][0]['value_key']));
        $this->assertSame([], $this->callsOfTable('main', 'upsert', 'furan_formbuilder_stat_stage_day'));
    }

    private function callsOfTable(string $source, string $type, string $table): array
    {
        return array_values(array_filter(
            $this->callsOf($source, $type),
            static fn (array $c): bool => $c[2] === $table
        ));
    }

    public function testItSwapsTheStagedTotalsIntoTheLiveAggregatesInOneShortTransactionUnderTheFolderLock(): void
    {
        $this->addSubmissions(1);
        $this->journalIds = [5, 6];
        $this->foldLockMisses = 3;

        $this->rebuilder->rebuild(1);

        $tags = $this->tags();
        $locks = array_keys(array_filter(
            $this->calls,
            static fn (array $c): bool => $c[0] === 'lock' && $c[1] === 'lock' && $c[2] === 'furanform_stats_fold'
        ));
        $this->assertCount(5, $locks);
        $swapLock = $locks[count($locks) - 1];
        $this->assertSame(60, $this->calls[$swapLock][3]);
        $unlock = array_keys(array_filter(
            $this->calls,
            static fn (array $c): bool => $c[0] === 'lock' && $c[1] === 'unlock' && $c[2] === 'furanform_stats_fold'
        ));
        $swapUnlock = end($unlock);
        $begin = array_keys(array_filter($tags, static fn (string $t): bool => $t === 'main:begin'));
        $swapBegin = end($begin);
        $commit = array_keys(array_filter($tags, static fn (string $t): bool => $t === 'main:commit'));
        $swapCommit = end($commit);
        $this->assertTrue($swapLock < $swapBegin && $swapCommit < $swapUnlock);

        $inside = array_slice($this->calls, $swapBegin, $swapCommit - $swapBegin);
        $sqls = [];
        foreach ($inside as $call) {
            $sqls[] = $call[1] === 'delete' ? 'DEL ' . $call[2] : ($call[1] === 'query' ? $call[2] : $call[1]);
        }
        $joined = implode("\n", $sqls);
        $this->assertStringContainsString('DEL furan_formbuilder_stat_day', $joined);
        $this->assertStringContainsString('DEL furan_formbuilder_stat_value', $joined);
        $this->assertStringContainsString('DEL furan_formbuilder_stat_number', $joined);
        $this->assertStringContainsString('DEL furan_formbuilder_stat_label', $joined);
        foreach (['day', 'value', 'number', 'label'] as $kind) {
            $this->assertMatchesRegularExpression(
                '/INSERT INTO furan_formbuilder_stat_' . $kind . ' .*SELECT .* FROM furan_formbuilder_stat_stage_' . $kind . '/s',
                $joined
            );
        }
        $this->assertStringContainsString('DELETE j FROM', $joined);
    }

    public function testItGivesUpWaitingForTheFolderLockAfterTenMinutes(): void
    {
        $this->addSubmissions(1);
        $this->foldLockMisses = 1000;

        try {
            $this->rebuilder->rebuild(1);
            $this->fail('Expected a failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('folder lock', $e->getMessage());
        }
        $this->assertSame(0, $this->level);
    }

    public function testItRemovesItsStagingRowsAfterTheSwapAndAfterAFailure(): void
    {
        $this->addSubmissions(1);
        $this->rebuilder->rebuild(1);
        $cleanups = array_values(array_filter(
            $this->callsOf('main', 'query'),
            static fn (array $c): bool => str_starts_with($c[2], 'DELETE FROM furan_formbuilder_stat_stage_')
                && str_contains($c[2], 'rebuild_id = ?')
        ));
        $this->assertCount(5, $cleanups);
        $this->assertSame([1, 7], $cleanups[0][3]);
        $this->assertStringContainsString('LIMIT 10000', $cleanups[0][2]);
        $this->assertStringContainsString('FROM furan_formbuilder_stat_stage_day', $cleanups[0][2]);

        $this->calls = [];
        $this->failOn = 'scan';
        try {
            $this->rebuilder->rebuild(1);
        } catch (\RuntimeException $e) {
            $this->assertSame('scan failed', $e->getMessage());
        }
        $after = array_filter(
            $this->callsOf('main', 'query'),
            static fn (array $c): bool => str_starts_with($c[2], 'DELETE FROM furan_formbuilder_stat_stage_')
                && str_contains($c[2], 'rebuild_id = ?')
        );
        $this->assertCount(5, $after);
    }

    public function testItRollsBackAndAsksForARerunWhenTheJournalDeleteAffectsFewerRowsThanStaged(): void
    {
        $this->addSubmissions(1);
        $this->journalIds = [5, 6];
        $this->journalDeleteOverride = 1;

        try {
            $this->rebuilder->rebuild(1);
            $this->fail('Expected a failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('rerun the rebuild', $e->getMessage());
        }

        $this->assertSame(0, $this->level);
        $updates = $this->callsOf('main', 'update');
        $this->assertSame(['rebuilding' => 0], end($updates)[3]);
        $this->assertNotContains(['rebuilding' => 0, 'needs_rebuild' => 0], array_column($updates, 3));
    }

    public function testItResetsSpecsOnlyAfterTheFormIsFlaggedAsRebuildingAndBeforeTheSnapshot(): void
    {
        $this->addSubmissions(1);

        $this->rebuilder->rebuild(1, true);

        $this->assertSame([['registry', 'resetSpecs', 1, ['colour']]], $this->callsOf('registry', 'resetSpecs'));
        $tags = $this->tags();
        $reset = array_search('registry:resetSpecs', $tags, true);
        $this->assertLessThan($reset, array_search('main:upsert', $tags, true));
        $this->assertLessThan(array_search('registry:specsFor', $tags, true), $reset);
        $this->assertLessThan(array_search('main:begin', $tags, true), $reset);
    }

    public function testItDoesNotResetSpecsUnlessAskedTo(): void
    {
        $this->rebuilder->rebuild(1);

        $this->assertSame([], $this->callsOf('registry', 'resetSpecs'));
    }

    public function testItFlagsTheFormAsNeedingARebuildWhenAResetSpecsRebuildFails(): void
    {
        $this->addSubmissions(1);
        $this->failOn = 'scan';

        try {
            $this->rebuilder->rebuild(1, true);
            $this->fail('Expected the failure to be rethrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('rerun', $e->getMessage());
        }

        $updates = $this->callsOf('main', 'update');
        $this->assertContains(['rebuilding' => 0, 'needs_rebuild' => 1], array_column($updates, 3));
    }

    public function testItDoesNotFlagARebuildNeededWhenAPlainRebuildFails(): void
    {
        $this->addSubmissions(1);
        $this->failOn = 'scan';

        try {
            $this->rebuilder->rebuild(1);
        } catch (\RuntimeException $e) {
            $this->assertSame('scan failed', $e->getMessage());
        }

        $this->assertNotContains(['rebuilding' => 0, 'needs_rebuild' => 1], array_column($this->callsOf('main', 'update'), 3));
    }
}
