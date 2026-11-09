<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Framework\App\ResourceConnection;

/** Reads and updates one delivery row by primary key. */
class DeliveryStore
{
    private const COLUMNS = [
        'delivery_id', 'endpoint_id', 'submission_id', 'event', 'event_data', 'webhook_id', 'attempts',
        'created_at', 'next_attempt_at',
    ];

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(int $deliveryId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(Outbox::TABLE), self::COLUMNS)
                ->where('delivery_id = ?', $deliveryId)
                ->limit(1)
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $bind
     */
    public function update(int $deliveryId, array $bind): void
    {
        $this->resource->getConnection()->update(
            $this->resource->getTableName(Outbox::TABLE),
            $bind,
            ['delivery_id = ?' => $deliveryId]
        );
    }
}
