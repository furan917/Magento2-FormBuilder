<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Throttle;

use Magento\Framework\App\ResourceConnection;

/**
 * Expiring counters shared by every web node. One INSERT … ON DUPLICATE KEY UPDATE per hit, so concurrent
 * requests never lose increments the way cache load + save does, and a cache flush cannot reset them.
 */
class Counter
{
    public const TABLE = 'furan_formbuilder_counter';

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly ResourceConnection $resource,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * Adds $by and returns the new total. An expired row starts again from $by with a fresh $ttl.
     */
    public function hit(string $key, int $ttl, int $by = 1): int
    {
        $now = (int)($this->clock)();
        $connection = $this->resource->getConnection();
        // LAST_INSERT_ID(expr) hands this connection the value it wrote, without a second racing read. The
        // transaction keeps the read on the same (primary) connection where read/write splitting is used.
        $connection->beginTransaction();
        try {
            $connection->query(
                'INSERT INTO ' . $this->resource->getTableName(self::TABLE) . ' (counter_key, hits, expires_at)'
                . ' VALUES (?, LAST_INSERT_ID(?), ?) ON DUPLICATE KEY UPDATE'
                . ' hits = LAST_INSERT_ID(IF(expires_at <= ?, ?, hits + ?)),'
                . ' expires_at = IF(expires_at <= ?, ?, expires_at)',
                [$this->rowKey($key), $by, $now + max(1, $ttl), $now, $by, $by, $now, $now + max(1, $ttl)]
            );
            $hits = (int)$connection->fetchOne('SELECT LAST_INSERT_ID()');
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $hits;
    }

    /** Current total; 0 when absent or expired. */
    public function peek(string $key): int
    {
        return (int)$this->resource->getConnection()->fetchOne(
            'SELECT hits FROM ' . $this->resource->getTableName(self::TABLE)
            . ' WHERE counter_key = ? AND expires_at > ?',
            [$this->rowKey($key), (int)($this->clock)()]
        );
    }

    public function clear(string $key): void
    {
        $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['counter_key = ?' => $this->rowKey($key)]
        );
    }

    /** Callers' keys can hold IPs or emails; only a digest is stored. */
    private function rowKey(string $key): string
    {
        return hash('sha256', $key, true);
    }
}
