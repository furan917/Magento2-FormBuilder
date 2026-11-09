<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Cron;

use Furan\FormBuilder\Model\Throttle\Counter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Removes expired rate-limit windows and used-token marks.
 */
class CounterCleanup
{
    public const LOCK_NAME = 'furanform_counter_cleanup';
    private const BATCH_SIZE = 1000;
    private const MAX_BATCHES = 200;

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger,
        private readonly LockManagerInterface $lockManager,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function execute(): void
    {
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return;
        }
        try {
            $sql = sprintf(
                'DELETE FROM %s WHERE expires_at < ? LIMIT %d',
                $this->resource->getTableName(Counter::TABLE),
                self::BATCH_SIZE
            );
            $now = (int)($this->clock)();
            $connection = $this->resource->getConnection();
            for ($i = 0; $i < self::MAX_BATCHES; $i++) {
                if ((int)$connection->query($sql, [$now])->rowCount() < self::BATCH_SIZE) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Form Builder could not remove expired counters: ' . $e->getMessage());
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }
}
