<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Framework\App\ResourceConnection;

/**
 * Deletes the deliveries of submissions about to be deleted (attempts and debug rows go by cascade), in bounded
 * primary key batches. Each statement autocommits, so call it before the submission transaction opens; the
 * submission FK cascade then only finds the few rows queued in between.
 */
class SubmissionDeliveryCleaner
{
    public const BATCH = 1000;

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @param int[] $submissionIds
     */
    public function deleteFor(array $submissionIds): void
    {
        if ($submissionIds === []) {
            return;
        }
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('furan_formbuilder_webhook_delivery');
        // Ids are ints, so inlining them is injection-safe.
        $sql = 'SELECT delivery_id FROM ' . $table . ' WHERE submission_id IN ('
            . implode(',', array_map('intval', $submissionIds)) . ') LIMIT ' . self::BATCH;
        do {
            $ids = array_map('intval', $connection->fetchCol($sql));
            if ($ids !== []) {
                $connection->delete($table, ['delivery_id IN (?)' => $ids]);
            }
        } while (count($ids) >= self::BATCH);
    }
}
