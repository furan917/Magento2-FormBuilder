<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Framework\App\ResourceConnection;

class DeliveryClaim
{
    public const LEASE_SECONDS = 120;

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /** Compare-and-swap by primary key: the claim is won only when exactly one row changed. */
    public function claim(int $deliveryId, int $now): bool
    {
        return $this->resource->getConnection()->update(
            $this->resource->getTableName(Outbox::TABLE),
            [
                'status' => DeliveryStatus::DELIVERING,
                'next_attempt_at' => gmdate('Y-m-d H:i:s', $now + self::LEASE_SECONDS),
            ],
            [
                'delivery_id = ?' => $deliveryId,
                'status IN (?)' => [DeliveryStatus::PENDING, DeliveryStatus::DELIVERING],
                'next_attempt_at <= ?' => gmdate('Y-m-d H:i:s', $now),
            ]
        ) === 1;
    }

    /**
     * One keyset page of an endpoint's due rows with the given status: a seek on
     * (status, endpoint_id, next_attempt_at, delivery_id).
     *
     * @param array{0: string, 1: int}|null $after cursor: next_attempt_at and delivery id of the last row seen
     * @return array<int, array{delivery_id: int, next_attempt_at: string}>
     */
    public function dueForEndpoint(int $status, int $endpointId, int $now, int $limit, ?array $after): array
    {
        $bind = [$status, $endpointId, gmdate('Y-m-d H:i:s', $now)];
        $cursor = '';
        if ($after !== null) {
            $cursor = ' AND next_attempt_at >= ? AND (next_attempt_at > ? OR delivery_id > ?)';
            array_push($bind, $after[0], $after[0], $after[1]);
        }
        $rows = $this->resource->getConnection()->fetchAll(
            'SELECT delivery_id, next_attempt_at FROM ' . $this->resource->getTableName(Outbox::TABLE)
            . ' WHERE status = ? AND endpoint_id = ? AND next_attempt_at <= ?' . $cursor
            . ' ORDER BY next_attempt_at, delivery_id LIMIT ' . max(1, $limit),
            $bind
        );

        return array_map(static fn (array $row): array => [
            'delivery_id' => (int)$row['delivery_id'],
            'next_attempt_at' => (string)$row['next_attempt_at'],
        ], $rows);
    }

    /**
     * Cancels up to $limit pending rows of an endpoint, never test sends. Ids first, then one UPDATE by id list
     * (UPDATE ... LIMIT is not replication safe). Cancelled rows leave the pending range, so no cursor is needed.
     *
     * @return int rows cancelled
     */
    public function cancelPending(int $endpointId, int $limit): int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(Outbox::TABLE);
        $ids = $connection->fetchCol(
            'SELECT delivery_id FROM ' . $table
            . ' WHERE status = ? AND endpoint_id = ? AND event <> ? ORDER BY next_attempt_at, delivery_id LIMIT '
            . max(1, $limit),
            [DeliveryStatus::PENDING, $endpointId, Outbox::TEST_EVENT]
        );
        if ($ids === []) {
            return 0;
        }

        return (int)$connection->update(
            $table,
            ['status' => DeliveryStatus::CANCELLED, 'last_error' => DeliveryStatus::ERROR_ENDPOINT_INACTIVE],
            ['delivery_id IN (?)' => array_map('intval', $ids), 'status = ?' => DeliveryStatus::PENDING]
        );
    }

    public function cancel(int $deliveryId, string $reason): void
    {
        $this->resource->getConnection()->update(
            $this->resource->getTableName(Outbox::TABLE),
            ['status' => DeliveryStatus::CANCELLED, 'last_error' => $reason],
            ['delivery_id = ?' => $deliveryId, 'status IN (?)' => [DeliveryStatus::PENDING, DeliveryStatus::DELIVERING]]
        );
    }

    /**
     * @param int[] $deliveryIds
     * @return array<int, int> delivery id => endpoint id, for the rows that exist
     */
    public function endpointsOf(array $deliveryIds): array
    {
        if ($deliveryIds === []) {
            return [];
        }
        $pairs = $this->resource->getConnection()->fetchPairs(
            'SELECT delivery_id, endpoint_id FROM ' . $this->resource->getTableName(Outbox::TABLE)
            . ' WHERE delivery_id IN (' . implode(',', array_map('intval', $deliveryIds)) . ')'
        );
        $map = [];
        foreach ($pairs as $deliveryId => $endpointId) {
            $map[(int)$deliveryId] = (int)$endpointId;
        }

        return $map;
    }
}
