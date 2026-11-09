<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Model\ResourceModel\Submission as SubmissionResource;
use Furan\FormBuilder\Model\Stats\Journal;
use Furan\FormBuilder\Model\Webhook\SubmissionDeliveryCleaner;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * One transaction per batch, not per row. Rows are re-read FOR UPDATE and only those are journaled and deleted,
 * so overlapping deleters or a concurrent edit cannot double-journal. The bulk DELETE skips model delete events
 * (none are observed); upload files are left to the cleanup cron. Webhook deliveries go first in autocommitted
 * bounded batches so the cascade inside the transaction stays small.
 */
class BatchDeleter
{
    public const BATCH_SIZE = 500;

    public function __construct(
        private readonly SubmissionResource $resource,
        private readonly Journal $journal,
        private readonly SubmissionDeliveryCleaner $deliveryCleaner
    ) {
    }

    /**
     * @param int[] $ids at most BATCH_SIZE
     * @return int how many were deleted
     */
    public function deleteBatch(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $this->deliveryCleaner->deleteFor($ids);
        $this->resource->beginTransaction();
        try {
            $rows = $this->lockRows($ids);
            if ($rows !== []) {
                $this->journal->remove($rows);
                $this->connection()->delete(
                    $this->resource->getMainTable(),
                    ['submission_id IN (?)' => array_map(static fn (array $row): int => (int)$row['submission_id'], $rows)]
                );
            }
            $this->resource->commit();
        } catch (\Throwable $e) {
            $this->resource->rollBack();
            throw $e;
        }
        return count($rows);
    }

    /**
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    private function lockRows(array $ids): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from(
                $this->resource->getMainTable(),
                ['submission_id', 'form_id', 'payload_json', 'created_at', 'store_id', 'customer_group_id']
            )
            ->where('submission_id IN (?)', $ids)
            ->forUpdate(true);

        return $connection->fetchAll($select);
    }

    private function connection(): AdapterInterface
    {
        $connection = $this->resource->getConnection();
        if (!$connection instanceof AdapterInterface) {
            throw new \RuntimeException('No database connection.');
        }

        return $connection;
    }
}
