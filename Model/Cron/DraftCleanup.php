<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Cron;

use Furan\FormBuilder\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class DraftCleanup
{
    public const LOCK_NAME = 'furanform_draft_cleanup';
    private const BATCH_SIZE = 500;
    private const BUDGET_SECONDS = 300;

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly LockManagerInterface $lockManager,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function execute(): void
    {
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return;
        }
        try {
            $this->purge();
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    private function purge(): void
    {
        $deadline = ($this->clock)() + self::BUDGET_SECONDS;
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('furan_formbuilder_draft');
        $removed = 0;
        try {
            foreach ($this->storesByLifetime() as $days => $storeIds) {
                $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
                // delete() cannot add LIMIT; the (store_id, updated_at) index keeps each batch a range seek.
                $sql = sprintf(
                    'DELETE FROM %s WHERE %s AND updated_at < ? LIMIT %d',
                    $table,
                    $connection->quoteInto('store_id IN (?)', $storeIds),
                    self::BATCH_SIZE
                );
                while (($this->clock)() < $deadline) {
                    $count = (int)$connection->query($sql, [$cutoff])->rowCount();
                    $removed += $count;
                    if ($count < self::BATCH_SIZE) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Form Builder could not remove old drafts: ' . $e->getMessage());
        }
        if ($removed > 0) {
            $this->logger->info(sprintf('Form Builder removed %d old draft(s).', $removed));
        }
    }

    /** @return array<int, list<int>> store ids keyed by their draft lifetime in days */
    private function storesByLifetime(): array
    {
        $groups = [];
        foreach ($this->storeManager->getStores(true) as $store) {
            $storeId = (int)$store->getId();
            $groups[$this->config->getServerDraftDays($storeId)][] = $storeId;
        }

        return $groups;
    }
}
