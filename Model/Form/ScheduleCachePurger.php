<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Form;

use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Indexer\CacheContextFactory;
use Magento\Framework\Lock\LockManagerInterface;

/** Purges the cached pages of forms whose open or close time has just passed. */
class ScheduleCachePurger
{
    public const LOCK_NAME = 'furanform_form_schedule';
    public const FLAG_CODE = 'furan_formbuilder_schedule_last_run';
    public const BATCH_SIZE = 1000;
    public const FIRST_RUN_WINDOW = 600;
    private const AFTER_ALL = PHP_INT_MAX;
    private const COLUMNS = ['open_from', 'close_at'];

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly FlagManager $flagManager,
        private readonly CacheContextFactory $cacheContextFactory,
        private readonly ManagerInterface $eventManager,
        private readonly LockManagerInterface $lockManager,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function run(int $budgetSeconds = 50): void
    {
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return;
        }
        try {
            $started = $now = (int)($this->clock)();
            $cursors = $this->loadCursors($now);
            do {
                $cursors = $this->purgeBatch($cursors, $now);
                $this->flagManager->saveFlag(self::FLAG_CODE, $cursors);
            } while (!$this->allDone($cursors, $now) && (int)($this->clock)() - $started < $budgetSeconds);
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /**
     * Per column, the last (time, form id) already purged; rows after it up to $now are still due.
     *
     * @return array<string, array{t: int, id: int}>
     */
    private function loadCursors(int $now): array
    {
        $saved = $this->flagManager->getFlagData(self::FLAG_CODE);
        $cursors = [];
        foreach (self::COLUMNS as $column) {
            $t = is_array($saved) ? (int)($saved[$column]['t'] ?? 0) : 0;
            $id = is_array($saved) ? (int)($saved[$column]['id'] ?? 0) : 0;
            $cursors[$column] = $t > 0
                ? ['t' => $t, 'id' => $id]
                : ['t' => $now - self::FIRST_RUN_WINDOW, 'id' => self::AFTER_ALL];
        }

        return $cursors;
    }

    /**
     * @param array<string, array{t: int, id: int}> $cursors
     * @return array<string, array{t: int, id: int}>
     */
    private function purgeBatch(array $cursors, int $now): array
    {
        $ids = [];
        foreach ($cursors as $column => $cursor) {
            if ($this->isDone($cursor, $now)) {
                continue;
            }
            $rows = $this->fetch($column, $cursor, $now);
            foreach ($rows as $row) {
                $ids[(int)$row['form_id']] = true;
            }
            $cursors[$column] = count($rows) >= self::BATCH_SIZE
                ? ['t' => $this->toTimestamp($rows[count($rows) - 1]['at']), 'id' => (int)$rows[count($rows) - 1]['form_id']]
                : ['t' => $now, 'id' => self::AFTER_ALL];
        }
        $this->purge(array_keys($ids));

        return $cursors;
    }

    /** @param array<string, array{t: int, id: int}> $cursors */
    private function allDone(array $cursors, int $now): bool
    {
        foreach ($cursors as $cursor) {
            if (!$this->isDone($cursor, $now)) {
                return false;
            }
        }

        return true;
    }

    /** @param array{t: int, id: int} $cursor */
    private function isDone(array $cursor, int $now): bool
    {
        return $cursor['t'] >= $now && $cursor['id'] === self::AFTER_ALL;
    }

    /**
     * Keyset page ordered by (column, form_id); each column index ends in the primary key.
     *
     * @param array{t: int, id: int} $cursor
     * @return array<int, array{form_id: string, at: string}>
     */
    private function fetch(string $column, array $cursor, int $to): array
    {
        $table = $this->resource->getTableName(FormResource::TABLE_FORM);
        $after = gmdate('Y-m-d H:i:s', $cursor['t']);

        return $this->resource->getConnection()->fetchAll(
            'SELECT form_id, ' . $column . ' AS at FROM ' . $table
            . ' WHERE is_active = 1 AND (' . $column . ' > ? OR (' . $column . ' = ? AND form_id > ?))'
            . ' AND ' . $column . ' <= ?'
            . ' ORDER BY ' . $column . ', form_id LIMIT ' . self::BATCH_SIZE,
            [$after, $after, $cursor['id'], gmdate('Y-m-d H:i:s', $to)]
        );
    }

    private function toTimestamp(string $utc): int
    {
        return (int)strtotime($utc . ' UTC');
    }

    /** @param array<int, int> $ids */
    private function purge(array $ids): void
    {
        if (!$ids) {
            return;
        }
        $context = $this->cacheContextFactory->create();
        $context->registerEntities(Form::CACHE_TAG, $ids);
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $context]);
    }
}
