<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Model\Event\SubmissionEvents;
use Furan\FormBuilder\Model\Thread\ThreadAccess;
use Furan\FormBuilder\Model\Webhook\SubmissionDeliveries;
use Magento\Framework\App\ResourceConnection;

/**
 * The only writer of a submission's status. The UPDATE and the webhook deliveries commit together; the
 * worker wake-up and the status_changed event follow the commit.
 */
class StatusWriter
{
    private const TABLE = 'furan_formbuilder_submission';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SubmissionDeliveries $deliveries,
        private readonly SubmissionEvents $events
    ) {
    }

    /**
     * Rows already in $status are updated too (closing again still revokes guest links) but are not reported.
     *
     * @param int[] $ids
     * @param string $source admin|customer|system
     * @param int|null $onlyFrom only rows currently in this status are touched
     * @return int submissions found
     */
    public function change(array $ids, int $status, string $source, ?int $onlyFrom = null): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return 0;
        }
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $connection->beginTransaction();
        try {
            $select = 'SELECT submission_id, form_id, store_id, status FROM ' . $table
                . ' WHERE ' . $connection->quoteInto('submission_id IN (?)', $ids)
                . ($onlyFrom === null ? '' : ' AND status = ' . $onlyFrom) . ' FOR UPDATE';
            /** @var array<int, array<string, string|null>> $rows */
            $rows = (array)$connection->fetchAll($select);
            if ($rows === []) {
                $connection->rollBack();
                return 0;
            }
            $found = array_map(static fn (array $row): int => (int)$row['submission_id'], $rows);
            $connection->update(
                $table,
                ThreadAccess::statusBind($status),
                $connection->quoteInto('submission_id IN (?)', $found)
            );
            $changed = array_values(array_filter($rows, static fn (array $row): bool => (int)$row['status'] !== $status));
            $deliveryIds = $changed === [] ? [] : $this->deliveries->recordMany(
                'submission.status_changed',
                array_map(static fn (array $row): array => [
                    'submissionId' => (int)$row['submission_id'],
                    'formId' => (int)$row['form_id'],
                    'storeId' => $row['store_id'] === null ? null : (int)$row['store_id'],
                    'eventData' => ['old' => (int)$row['status'], 'new' => $status, 'source' => $source],
                ], $changed)
            );
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        $this->deliveries->wake($deliveryIds);
        foreach ($changed as $row) {
            $this->events->statusChanged(
                (int)$row['submission_id'],
                (int)$row['form_id'],
                (int)$row['status'],
                $status,
                $source
            );
        }

        return count($found);
    }
}
