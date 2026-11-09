<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Stats;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Model\ResourceModel\Submission\KeysetIndex;
use Furan\FormBuilder\Model\Schema\SchemaFields;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Model\ResourceModel\Type\Db\ConnectionFactoryInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Recomputes one form's aggregates from its submissions. A read-only REPEATABLE READ snapshot on the main
 * connection feeds per-batch totals into staging tables over a second autocommit connection; a short
 * transaction under the folder lock then swaps staging into the live tables. Memory is one batch.
 *
 * @phpstan-import-type Dims from DeltaCalculator
 * @phpstan-import-type Spec from DeltaCalculator
 */
class Rebuilder
{
    private const BATCH_SIZE = 500;
    private const JOURNAL_CHUNK = 1000;
    private const INSERT_CHUNK = 1000;
    private const STAGE_CHUNK = 10000;
    private const PROGRESS_EVERY = 10000;
    private const LOCK_WAIT = 60;
    private const LOCK_GIVE_UP_SECONDS = 600;
    private const TIMEZONE_PATH = 'general/locale/timezone';
    private const STAGE_TABLES = ['day', 'value', 'number', 'label', 'journal'];
    // live table => columns copied from its staging table (form_id and rebuild_id stay behind)
    private const SWAP_COLUMNS = [
        'day' => 'form_id, grain, is_rollup, period, store_id, customer_group_id, submissions',
        'value' => 'field_id, grain, is_rollup, period, store_id, customer_group_id, value_key, hits',
        'number' => 'field_id, grain, is_rollup, period, store_id, customer_group_id, n, total',
        'label' => 'field_id, value_key, label',
    ];

    /** @var callable */
    private $clock;
    private ?AdapterInterface $stageConnection = null;
    /** @var array<int, string> */
    private array $timezones = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ConnectionFactoryInterface $connectionFactory,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly LockManagerInterface $lockManager,
        private readonly FormRepositoryInterface $formRepository,
        private readonly SeriesRegistry $seriesRegistry,
        private readonly DeltaCalculator $calculator,
        private readonly AggregateMerger $merger,
        private readonly Json $json,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger,
        private readonly KeysetIndex $keysetIndex,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** @throws \RuntimeException when refused or failed; the old aggregates stay in place */
    public function rebuild(int $formId, bool $resetSpecs = false): void
    {
        $rebuildLock = Folder::REBUILD_LOCK_PREFIX . $formId;
        if (!$this->lockManager->lock($rebuildLock, 0)) {
            throw new \RuntimeException(sprintf('A rebuild of form %d is already running.', $formId));
        }
        $rebuildId = null;
        $specsTouched = false;
        try {
            $rebuildId = $this->flag($formId);
            if ($resetSpecs) {
                $specsTouched = true;
                $this->resetSpecs($formId);
            }
            $this->prepareSpecs($formId);
            [$fieldIds, $journalCount] = $this->snapshot($formId, $rebuildId);
            $this->swap($formId, $rebuildId, $fieldIds, $journalCount);
            $this->clearStagingQuietly($formId, $rebuildId);
        } catch (\Throwable $e) {
            $this->abort($formId, $rebuildId, $specsTouched);
            $e = $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
            throw $specsTouched
                ? new \RuntimeException($e->getMessage() . ' The specs were reset; rerun the rebuild.', 0, $e)
                : $e;
        } finally {
            $this->lockManager->unlock($rebuildLock);
        }
    }

    /** @return int the rebuild id the staging rows carry */
    private function flag(int $formId): int
    {
        if (!$this->lockManager->lock(Folder::LOCK_NAME, self::LOCK_WAIT)) {
            throw new \RuntimeException('Could not get the folder lock; try again shortly.');
        }
        try {
            $this->connection()->insertOnDuplicate(
                $this->table('stat_state'),
                [[
                    'form_id' => $formId,
                    'rebuilding' => 1,
                    'rebuild_started_at' => gmdate('Y-m-d H:i:s'),
                    'rebuild_id' => 1,
                ]],
                ['rebuilding', 'rebuild_started_at', 'rebuild_id' => new \Zend_Db_Expr('rebuild_id + 1')]
            );
            $rebuildId = (int)$this->connection()->fetchOne(
                'SELECT rebuild_id FROM ' . $this->table('stat_state') . ' WHERE form_id = ?',
                [$formId]
            );
            $this->clearStaging($formId, null);
        } finally {
            $this->lockManager->unlock(Folder::LOCK_NAME);
        }
        return $rebuildId;
    }

    /** Creates missing specs for the current schema; existing ones are never changed. */
    private function prepareSpecs(int $formId): void
    {
        $this->seriesRegistry->specsFor($formId, $this->schemaFields($formId));
    }

    private function resetSpecs(int $formId): void
    {
        $this->seriesRegistry->resetSpecs($formId, $this->schemaFields($formId));
    }

    /** @return list<array<string, mixed>> */
    private function schemaFields(int $formId): array
    {
        $schema = $this->formRepository->getById($formId)->getSchema();
        return SchemaFields::all($schema);
    }

    /** @return array{0: array<string, int>, 1: int} field_key => field_id as seen by the snapshot, staged journal rows */
    private function snapshot(int $formId, int $rebuildId): array
    {
        $connection = $this->connection();
        if ($connection->getTransactionLevel() !== 0) {
            throw new \RuntimeException('The rebuild cannot start inside an open transaction.');
        }
        // Applies to the next transaction only; without it READ-COMMITTED servers would re-snapshot every batch.
        $connection->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $connection->beginTransaction();
        // Only non-locking SELECTs from here to commit. The first one creates the read view.
        $specs = $this->seriesRegistry->load($formId);
        $fieldIds = array_map('intval', $connection->fetchPairs(
            'SELECT field_key, field_id FROM ' . $this->table('stat_field') . ' WHERE form_id = ?',
            [$formId]
        ));
        $scanned = $this->stageSubmissions($formId, $rebuildId, $specs, $fieldIds);
        $journal = $this->stageJournalIds($formId, $rebuildId);
        $connection->commit();
        $this->logger->info(sprintf(
            'Stats rebuild of form %d: %d submissions, %d journal rows staged.',
            $formId,
            $scanned,
            $journal
        ));
        return [$fieldIds, $journal];
    }

    /**
     * @param array<string, Spec> $specs
     * @param array<string, int> $fieldIds
     */
    private function stageSubmissions(int $formId, int $rebuildId, array $specs, array $fieldIds): int
    {
        $last = 0;
        $scanned = 0;
        $nextReport = self::PROGRESS_EVERY;
        do {
            $rows = $this->connection()->fetchAll(
                'SELECT submission_id, payload_json, created_at, store_id, customer_group_id FROM '
                . $this->table('submission') . $this->keysetIndex->forForm()
                . ' WHERE form_id = ? AND submission_id > ? ORDER BY submission_id LIMIT '
                . self::BATCH_SIZE,
                [$formId, $last]
            );
            if ($rows === []) {
                break;
            }
            $deltas = [];
            foreach ($rows as $row) {
                $payload = $this->json->unserialize((string)$row['payload_json']);
                array_push($deltas, ...$this->calculator->compute(
                    is_array($payload) ? $payload : [],
                    $this->dims($row),
                    $specs,
                    1
                ));
            }
            $this->stage($formId, $rebuildId, $fieldIds, $this->merger->merge($deltas));
            $last = (int)end($rows)['submission_id'];
            $scanned += count($rows);
            if ($scanned >= $nextReport) {
                $nextReport += self::PROGRESS_EVERY;
                $this->logger->info(sprintf(
                    'Stats rebuild of form %d: %d submissions scanned, peak memory %d MB.',
                    $formId,
                    $scanned,
                    memory_get_peak_usage(true) >> 20
                ));
            }
        } while (count($rows) === self::BATCH_SIZE);
        return $scanned;
    }

    private function stageJournalIds(int $formId, int $rebuildId): int
    {
        $last = 0;
        $total = 0;
        do {
            $ids = $this->connection()->fetchCol(
                'SELECT journal_id FROM ' . $this->table('stat_journal')
                . ' WHERE form_id = ? AND journal_id > ? ORDER BY journal_id LIMIT ' . self::JOURNAL_CHUNK,
                [$formId, $last]
            );
            if ($ids === []) {
                break;
            }
            $this->stageConnection()->insertMultiple($this->table('stat_stage_journal'), array_map(
                static fn ($id): array => [
                    'form_id' => $formId,
                    'rebuild_id' => $rebuildId,
                    'journal_id' => (int)$id,
                    'deltas' => '',
                ],
                $ids
            ));
            $last = (int)end($ids);
            $total += count($ids);
        } while (count($ids) === self::JOURNAL_CHUNK);
        return $total;
    }

    /**
     * @param array<string, int> $fieldIds
     * @param array{day: list<array<string, mixed>>, value: list<array<string, mixed>>, number: list<array<string, mixed>>, label: list<array<string, mixed>>} $merged
     */
    private function stage(int $formId, int $rebuildId, array $fieldIds, array $merged): void
    {
        $key = ['form_id' => $formId, 'rebuild_id' => $rebuildId];
        $rows = ['day' => [], 'value' => [], 'number' => [], 'label' => []];
        foreach ($merged['day'] as $row) {
            $rows['day'][] = $key + $row;
        }
        foreach (['value', 'number', 'label'] as $kind) {
            foreach ($merged[$kind] as $row) {
                if (isset($fieldIds[$row['field_key']])) {
                    $rows[$kind][] = $key + $this->withFieldId($row, $fieldIds[$row['field_key']]);
                }
            }
        }
        $this->upsert('day', $rows['day'], ['submissions' => $this->additive('submissions')]);
        $this->upsert('value', $rows['value'], ['hits' => $this->additive('hits')]);
        $this->upsert('number', $rows['number'], ['n' => $this->additive('n'), 'total' => $this->additive('total')]);
        $this->upsert('label', $rows['label'], ['label']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int|string, mixed> $update
     */
    private function upsert(string $kind, array $rows, array $update): void
    {
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $this->stageConnection()->insertOnDuplicate($this->table('stat_stage_' . $kind), $chunk, $update);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withFieldId(array $row, int $fieldId): array
    {
        unset($row['field_key']);
        $row = ['field_id' => $fieldId] + $row;
        if (isset($row['value_key'])) {
            $row['value_key'] = hex2bin((string)$row['value_key']);
        }
        if (isset($row['value'])) {
            $row['label'] = $row['value'];
            unset($row['value']);
        }
        return $row;
    }

    private function additive(string $column): \Zend_Db_Expr
    {
        return new \Zend_Db_Expr($column . ' + VALUES(' . $column . ')');
    }

    /** @param array<string, int> $fieldIds */
    private function swap(int $formId, int $rebuildId, array $fieldIds, int $journalCount): void
    {
        $this->acquireFoldLock();
        try {
            $this->swapUnderLock($formId, $rebuildId, array_values($fieldIds), $journalCount);
        } finally {
            $this->lockManager->unlock(Folder::LOCK_NAME);
        }
    }

    private function acquireFoldLock(): void
    {
        $start = ($this->clock)();
        while (!$this->lockManager->lock(Folder::LOCK_NAME, self::LOCK_WAIT)) {
            if (($this->clock)() - $start >= self::LOCK_GIVE_UP_SECONDS) {
                throw new \RuntimeException('Could not get the folder lock after 10 minutes; rerun the rebuild.');
            }
            $this->logger->info('Stats rebuild is waiting for the folder lock.');
        }
    }

    /** @param list<int> $fieldIds */
    private function swapUnderLock(int $formId, int $rebuildId, array $fieldIds, int $journalCount): void
    {
        $connection = $this->connection();
        $bind = [$formId, $rebuildId];
        $connection->beginTransaction();
        try {
            $connection->delete($this->table('stat_day'), ['form_id = ?' => $formId]);
            if ($fieldIds !== []) {
                foreach (['value', 'number', 'label'] as $kind) {
                    $connection->delete($this->table('stat_' . $kind), ['field_id IN (?)' => $fieldIds]);
                }
            }
            foreach (self::SWAP_COLUMNS as $kind => $columns) {
                $connection->query(
                    'INSERT INTO ' . $this->table('stat_' . $kind) . ' (' . $columns . ') SELECT ' . $columns
                    . ' FROM ' . $this->table('stat_stage_' . $kind) . ' WHERE form_id = ? AND rebuild_id = ?',
                    $bind
                );
            }
            $deleted = $connection->query(
                'DELETE j FROM ' . $this->table('stat_journal') . ' j JOIN ' . $this->table('stat_stage_journal')
                . ' s ON s.journal_id = j.journal_id WHERE s.form_id = ? AND s.rebuild_id = ?',
                $bind
            )->rowCount();
            if ($deleted !== $journalCount) {
                throw new \RuntimeException(
                    'A journal row was applied by another folder during the swap; rerun the rebuild.'
                );
            }
            $connection->update(
                $this->table('stat_state'),
                ['rebuilding' => 0, 'needs_rebuild' => 0],
                ['form_id = ?' => $formId]
            );
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /** After a spec reset the old aggregates no longer match the specs, so the form must be rebuilt again. */
    private function abort(int $formId, ?int $rebuildId, bool $specsTouched): void
    {
        try {
            while ($this->connection()->getTransactionLevel() > 0) {
                $this->connection()->rollBack();
            }
            $this->connection()->update(
                $this->table('stat_state'),
                $specsTouched ? ['rebuilding' => 0, 'needs_rebuild' => 1] : ['rebuilding' => 0],
                ['form_id = ?' => $formId]
            );
            if ($rebuildId !== null) {
                $this->clearStaging($formId, $rebuildId);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Stats rebuild cleanup failed: ' . $e->getMessage());
        }
    }

    private function clearStagingQuietly(int $formId, int $rebuildId): void
    {
        try {
            $this->clearStaging($formId, $rebuildId);
        } catch (\Throwable $e) {
            $this->logger->warning('Stats rebuild could not remove its staging rows: ' . $e->getMessage());
        }
    }

    /** Chunked so no single statement holds a large lock set. */
    private function clearStaging(int $formId, ?int $rebuildId): void
    {
        $where = 'form_id = ?' . ($rebuildId === null ? '' : ' AND rebuild_id = ?');
        $bind = $rebuildId === null ? [$formId] : [$formId, $rebuildId];
        foreach (self::STAGE_TABLES as $name) {
            do {
                $removed = $this->connection()->query(
                    'DELETE FROM ' . $this->table('stat_stage_' . $name) . ' WHERE ' . $where
                    . ' LIMIT ' . self::STAGE_CHUNK,
                    $bind
                )->rowCount();
            } while ($removed >= self::STAGE_CHUNK);
        }
    }

    /**
     * Dimensions are derived exactly as Journal derives them, or removals would miss their buckets.
     *
     * @param array<string, mixed> $row
     * @return Dims
     */
    private function dims(array $row): array
    {
        $storeId = (int)($row['store_id'] ?? 0);
        return [
            'store_id' => $storeId,
            'customer_group_id' => (int)$row['customer_group_id'],
            'created_at' => (string)$row['created_at'],
            'timezone' => $this->timezone($storeId),
        ];
    }

    private function timezone(int $storeId): string
    {
        if (!isset($this->timezones[$storeId])) {
            $timezone = $storeId > 0
                ? $this->scopeConfig->getValue(self::TIMEZONE_PATH, ScopeInterface::SCOPE_STORE, $storeId)
                : $this->scopeConfig->getValue(self::TIMEZONE_PATH);
            $this->timezones[$storeId] = is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
        }
        return $this->timezones[$storeId];
    }

    private function stageConnection(): AdapterInterface
    {
        return $this->stageConnection ??= $this->connectionFactory->create(
            (array)$this->deploymentConfig->get('db/connection/default')
        );
    }

    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName('furan_formbuilder_' . $name);
    }
}
