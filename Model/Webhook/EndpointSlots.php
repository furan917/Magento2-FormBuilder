<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Framework\App\ResourceConnection;

/**
 * At most two deliveries in flight per endpoint, as two lease columns on the endpoint row. A crashed worker's
 * lease expires by itself; counting delivery rows would scan a table that can hold millions.
 */
class EndpointSlots
{
    public const SLOTS = 2;

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /** @return int|null the slot won, or null when both are leased */
    public function acquire(int $endpointId, int $now): ?int
    {
        for ($slot = 0; $slot < self::SLOTS; $slot++) {
            $column = 'slot' . $slot . '_until';
            $won = $this->resource->getConnection()->update(
                $this->table(),
                [$column => $this->leaseEnd($now)],
                [
                    'endpoint_id = ?' => $endpointId,
                    $column . ' IS NULL OR ' . $column . ' < ?' => gmdate('Y-m-d H:i:s', $now),
                ]
            );
            if ($won === 1) {
                return $slot;
            }
        }

        return null;
    }

    /** Told apart from busy slots only on a miss: the delivery table has no FK to the endpoint. */
    public function endpointExists(int $endpointId): bool
    {
        return $this->resource->getConnection()->fetchOne(
            'SELECT 1 FROM ' . $this->table() . ' WHERE endpoint_id = ?',
            [$endpointId]
        ) !== false;
    }

    /** Clears the lease only while it is still the one taken at $now; a successor's lease is left alone. */
    public function release(int $endpointId, int $slot, int $now): void
    {
        $column = 'slot' . $slot . '_until';
        $this->resource->getConnection()->update(
            $this->table(),
            [$column => null],
            ['endpoint_id = ?' => $endpointId, $column . ' = ?' => $this->leaseEnd($now)]
        );
    }

    private function leaseEnd(int $now): string
    {
        return gmdate('Y-m-d H:i:s', $now + DeliveryClaim::LEASE_SECONDS);
    }

    private function table(): string
    {
        return $this->resource->getTableName(EndpointRepository::TABLE);
    }
}
