<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Cron;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\ResourceModel\Submission as SubmissionResource;
use Furan\FormBuilder\Model\Submission\BatchDeleter;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

class Retention
{
    public const LOCK_NAME = 'furanform_retention';
    // Hourly runs; a backlog after enabling retention is worked off over several runs.
    private const BUDGET_SECONDS = 300;

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly Config $config,
        private readonly SubmissionResource $resource,
        private readonly BatchDeleter $batchDeleter,
        private readonly LoggerInterface $logger,
        private readonly LockManagerInterface $lockManager,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function execute(): void
    {
        $days = $this->config->getRetentionDays();
        if ($days <= 0) {
            return;
        }
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return;
        }
        try {
            $this->purge(gmdate('Y-m-d H:i:s', time() - $days * 86400));
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    private function purge(string $cutoff): void
    {
        $deadline = ($this->clock)() + self::BUDGET_SECONDS;
        $deleted = 0;
        $after = null;
        try {
            while (($this->clock)() < $deadline) {
                $rows = $this->nextRows($cutoff, $after);
                if ($rows === []) {
                    break;
                }
                $last = end($rows);
                $after = [(string)$last['created_at'], (int)$last['submission_id']];
                $deleted += $this->batchDeleter->deleteBatch(array_map('intval', array_column($rows, 'submission_id')));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Form Builder retention stopped: ' . $e->getMessage(), ['exception' => $e]);
        }
        if ($deleted > 0) {
            $this->logger->info(sprintf('Form Builder retention deleted %d submission(s).', $deleted));
        }
    }

    /**
     * Keyset on the created_at index (InnoDB appends the primary key), so each batch reads only old rows.
     *
     * @param array{0: string, 1: int}|null $after
     * @return array<int, array{submission_id: string, created_at: string}>
     */
    private function nextRows(string $cutoff, ?array $after): array
    {
        $sql = 'SELECT submission_id, created_at FROM ' . $this->resource->getMainTable() . ' WHERE created_at < ?';
        $bind = [$cutoff];
        if ($after !== null) {
            $sql .= ' AND (created_at > ? OR (created_at = ? AND submission_id > ?))';
            array_push($bind, $after[0], $after[0], $after[1]);
        }
        $sql .= ' ORDER BY created_at, submission_id LIMIT ' . BatchDeleter::BATCH_SIZE;

        /** @var AdapterInterface $connection */
        $connection = $this->resource->getConnection();

        return $connection->fetchAll($sql, $bind);
    }
}
