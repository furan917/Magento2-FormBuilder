<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/**
 * Failure state of an endpoint. Every write is one atomic statement, because up to two deliveries of the same
 * endpoint are in flight at once.
 */
class EndpointState
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /** One more failed delivery; the first one of a streak stamps failing_since. */
    public function recordFailure(int $endpointId, string $now, ?string $circuitOpenUntil): void
    {
        $this->resource->getConnection()->update(
            $this->resource->getTableName(EndpointRepository::TABLE),
            [
                'consecutive_failures' => new Expression('consecutive_failures + 1'),
                'failing_since' => new Expression('COALESCE(failing_since, ' . $this->quote($now) . ')'),
                'circuit_open_until' => $circuitOpenUntil,
            ],
            ['endpoint_id = ?' => $endpointId]
        );
    }

    public function reset(int $endpointId): void
    {
        $this->resource->getConnection()->update(
            $this->resource->getTableName(EndpointRepository::TABLE),
            ['consecutive_failures' => 0, 'failing_since' => null, 'circuit_open_until' => null],
            ['endpoint_id = ?' => $endpointId]
        );
    }

    /** @return bool true only for the caller that actually switched the endpoint off */
    public function disable(int $endpointId, string $reason): bool
    {
        return $this->resource->getConnection()->update(
            $this->resource->getTableName(EndpointRepository::TABLE),
            ['is_active' => 0, 'disabled_reason' => $reason],
            ['endpoint_id = ?' => $endpointId, 'is_active = 1']
        ) === 1;
    }

    private function quote(string $value): string
    {
        return (string)$this->resource->getConnection()->quote($value);
    }
}
