<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Stats;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * The only writer of the live aggregates besides the rebuild. Applies journal rows additively and deletes
 * exactly the ids it applied in the same transaction, so ids committing out of order cannot be lost.
 *
 * @phpstan-import-type Delta from DeltaCalculator
 */
class Folder
{
    public const LOCK_NAME = 'furanform_stats_fold';
    public const REBUILD_LOCK_PREFIX = 'furanform_stats_rebuild_';

    private const BATCH_SIZE = 500;
    private const INSERT_CHUNK = 1000;
    private const PENDING_CAP = 10001;
    private const STAGE_CHUNK = 10000;
    private const STAGE_TABLES = ['day', 'value', 'number', 'label', 'journal'];

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LockManagerInterface $lockManager,
        private readonly AggregateMerger $merger,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** Folds batches of any form until the journal is empty or the budget (seconds) is spent. */
    public function run(int $budgetSeconds): void
    {
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return;
        }
        try {
            $start = ($this->clock)();
            $deadline = $start + $budgetSeconds;
            $this->clearStaleFlags(null, $deadline);
            while (($this->clock)() - $start < $budgetSeconds && $this->foldBatch(null) > 0) {
                continue;
            }
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /** @return int journal rows still pending for the form, capped at 10,001 */
    public function foldForm(int $formId, int $maxBatches): int
    {
        if ($this->lockManager->lock(self::LOCK_NAME, 0)) {
            try {
                $this->clearStaleFlags($formId, PHP_FLOAT_MAX);
                $batches = 0;
                while ($batches < $maxBatches && $this->foldBatch($formId) > 0) {
                    $batches++;
                }
            } finally {
                $this->lockManager->unlock(self::LOCK_NAME);
            }
        }
        return (int)$this->connection()->fetchOne(
            'SELECT COUNT(*) FROM (SELECT 1 FROM ' . $this->table('stat_journal')
            . ' j WHERE j.form_id = ? LIMIT ' . self::PENDING_CAP . ') t',
            [$formId]
        );
    }

    /** @return int rows consumed; 0 when there was nothing to do or the batch was left for the next run */
    private function foldBatch(?int $formId): int
    {
        $connection = $this->connection();
        $rows = $this->nextRows($formId);
        if ($rows === []) {
            return 0;
        }
        try {
            $connection->beginTransaction();
            $existing = array_map('intval', $connection->fetchCol(
                'SELECT form_id FROM ' . $this->table('form') . ' WHERE form_id IN ('
                . implode(',', array_unique(array_map(static fn (array $r): int => (int)$r['form_id'], $rows)))
                . ') LOCK IN SHARE MODE'
            ));
            $rows = array_values(array_filter(
                $rows,
                static fn (array $r): bool => in_array((int)$r['form_id'], $existing, true)
            ));
            $ids = array_map(static fn (array $r): int => (int)$r['journal_id'], $rows);
            if ($ids !== [] && !$this->claim($ids)) {
                $connection->rollBack();
                $this->logger->warning('Stats folder: journal rows were taken by another folder.');
                return 0;
            }
            $this->apply($rows);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->logger->error('Stats folder batch failed, rows stay for the next run: ' . $e->getMessage());
            return 0;
        }
        return max(count($rows), 1);
    }

    /** @return array<int, array{journal_id: mixed, form_id: mixed, deltas: string}> */
    private function nextRows(?int $formId): array
    {
        $journal = $this->table('stat_journal');
        if ($formId !== null) {
            return $this->connection()->fetchAll(
                'SELECT j.journal_id, j.form_id, j.deltas FROM ' . $journal
                . ' j WHERE j.form_id = ? ORDER BY j.journal_id LIMIT ' . self::BATCH_SIZE,
                [$formId]
            );
        }
        // Joining stat_state made MySQL scan and sort the whole journal per batch; read the (tiny) exclusion list first.
        $rebuilding = array_map('intval', $this->connection()->fetchCol(
            'SELECT form_id FROM ' . $this->table('stat_state') . ' WHERE rebuilding <> 0'
        ));
        $where = $rebuilding === [] ? '' : ' WHERE j.form_id NOT IN (' . implode(',', array_fill(0, count($rebuilding), '?')) . ')';
        return $this->connection()->fetchAll(
            'SELECT j.journal_id, j.form_id, j.deltas FROM ' . $journal . ' j' . $where
            . ' ORDER BY j.journal_id LIMIT ' . self::BATCH_SIZE,
            $rebuilding
        );
    }

    /** @param int[] $ids */
    private function claim(array $ids): bool
    {
        $deleted = $this->connection()->delete($this->table('stat_journal'), ['journal_id IN (?)' => $ids]);
        return $deleted === count($ids);
    }

    /** @param list<array<string, mixed>> $rows stat_journal rows */
    private function apply(array $rows): void
    {
        $byForm = [];
        foreach ($rows as $row) {
            /** @var list<Delta> $deltas */
            $deltas = $this->json->unserialize($row['deltas'])['d'] ?? [];
            $byForm[(int)$row['form_id']] = [...($byForm[(int)$row['form_id']] ?? []), ...$deltas];
        }
        $tables = ['day' => [], 'value' => [], 'number' => [], 'label' => []];
        foreach ($byForm as $formId => $deltas) {
            $merged = $this->merger->merge($deltas);
            $fieldIds = array_map('intval', $this->connection()->fetchPairs(
                'SELECT field_key, field_id FROM ' . $this->table('stat_field') . ' WHERE form_id = ?',
                [$formId]
            ));
            foreach ($merged['day'] as $row) {
                $tables['day'][] = ['form_id' => $formId] + $row;
            }
            foreach (['value', 'number', 'label'] as $kind) {
                foreach ($merged[$kind] as $row) {
                    if (isset($fieldIds[$row['field_key']])) {
                        $tables[$kind][] = $this->withFieldId($row, $fieldIds[$row['field_key']]);
                    }
                }
            }
        }
        $this->upsert('stat_day', $tables['day'], ['submissions' => $this->additive('submissions')]);
        $this->upsert('stat_value', $tables['value'], ['hits' => $this->additive('hits')]);
        $this->upsert('stat_number', $tables['number'], [
            'n' => $this->additive('n'),
            'total' => $this->additive('total'),
        ]);
        $this->upsert('stat_label', $tables['label'], ['label']);
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

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int|string, mixed> $update
     */
    private function upsert(string $table, array $rows, array $update): void
    {
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $this->connection()->insertOnDuplicate($this->table($table), $chunk, $update);
        }
    }

    private function clearStaleFlags(?int $formId, float $deadline): void
    {
        $sql = 'SELECT form_id FROM ' . $this->table('stat_state') . ' WHERE rebuilding = 1';
        $bind = [];
        if ($formId !== null) {
            $sql .= ' AND form_id = ?';
            $bind = [$formId];
        }
        foreach ($this->connection()->fetchCol($sql, $bind) as $stale) {
            $stale = (int)$stale;
            if ($this->lockManager->isLocked(self::REBUILD_LOCK_PREFIX . $stale)) {
                continue;
            }
            $this->connection()->update(
                $this->table('stat_state'),
                ['rebuilding' => 0],
                ['form_id = ?' => $stale]
            );
            $this->logger->warning('Stats folder cleared a stale rebuilding flag for form ' . $stale);
            $this->clearStaging($stale, $deadline);
        }
    }

    private function clearStaging(int $formId, float $deadline): void
    {
        foreach (self::STAGE_TABLES as $name) {
            do {
                if (($this->clock)() >= $deadline) {
                    return;
                }
                $removed = $this->connection()->query(
                    'DELETE FROM ' . $this->table('stat_stage_' . $name)
                    . ' WHERE form_id = ? LIMIT ' . self::STAGE_CHUNK,
                    [$formId]
                )->rowCount();
            } while ($removed >= self::STAGE_CHUNK);
        }
    }

    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name === 'form' ? 'furan_formbuilder_form' : 'furan_formbuilder_' . $name);
    }
}
