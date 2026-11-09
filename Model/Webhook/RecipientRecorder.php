<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Framework\App\ResourceConnection;

/** Keeps who received a submission (ids and host only) after the delivery rows are purged, for GDPR Art. 15. */
class RecipientRecorder
{
    public const TABLE = 'furan_formbuilder_webhook_recipient';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /** The first delivery wins: a repeat only re-assigns the key column. */
    public function record(int $submissionId, int $endpointId, string $host, string $deliveredAt): void
    {
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            [
                'submission_id' => $submissionId,
                'endpoint_id' => $endpointId,
                'host' => $host,
                'first_delivered_at' => $deliveredAt,
            ],
            ['submission_id']
        );
    }
}
