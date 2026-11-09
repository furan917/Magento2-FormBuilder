<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Hourly retention for the webhook tables. Every phase selects ids through an index and deletes them by primary
 * key in bounded batches; attempts and debug excerpts go with their delivery by FK cascade.
 */
class RetentionPurger
{
    public const LOCK_NAME = 'furanform_webhook_retention';
    public const FLAG_CODE = 'furan_formbuilder_webhook_retention_cursor';
    public const BUDGET_SECONDS = 240;
    public const DELIVERY_BATCH = 500;
    public const ENDPOINT_BUDGET_SECONDS = 60;
    public const DEBUG_BATCH = 1000;
    public const DEBUG_DAYS = 7;
    public const ENDPOINT_PAGE = 50;
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 365;
    private const RETENTION_PATH = 'furan_formbuilder/webhooks/retention_days';

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly FlagManager $flagManager,
        private readonly LockManagerInterface $lockManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function run(): void
    {
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return;
        }
        try {
            $started = $this->now();
            $this->purgeDebugExcerpts($started);
            $this->purgeDeletedEndpoints($started);
            $this->purgeDeliveries($started);
        } catch (\Throwable $e) {
            $this->logger->error('Webhook retention failed.', ['exception_class' => $e::class]);
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /** Short-lived by promise, so it runs before the delivery backlog can use up the budget. */
    private function purgeDebugExcerpts(int $started): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('furan_formbuilder_webhook_attempt_debug');
        $cutoff = gmdate('Y-m-d H:i:s', $started - self::DEBUG_DAYS * 86400);
        $afterAt = '1000-01-01 00:00:00';
        $afterId = 0;
        while ($this->now() - $started < self::BUDGET_SECONDS) {
            $rows = $connection->fetchAll(
                'SELECT attempt_id, created_at FROM ' . $table . ' WHERE created_at < ?'
                . ' AND (created_at, attempt_id) > (?, ?) ORDER BY created_at, attempt_id LIMIT ' . self::DEBUG_BATCH,
                [$cutoff, $afterAt, $afterId]
            );
            if ($rows === []) {
                return;
            }
            $last = end($rows);
            $afterAt = (string)$last['created_at'];
            $afterId = (int)$last['attempt_id'];
            $connection->delete($table, ['attempt_id IN (?)' => array_map('intval', array_column($rows, 'attempt_id'))]);
            if (count($rows) < self::DEBUG_BATCH) {
                return;
            }
        }
    }

    /**
     * Delivery ids grow with created_at, so the scan stops at the first row inside the retention period. The
     * persisted cursor is a low-water mark: the id before the first kept (non-final) row, so a kept row is looked at
     * again once it finalises while everything behind the mark is already gone. A non-final row is also deleted
     * once it is older than the retention period and the delivery window plus a day: the policy expires it anyway,
     * and a row that can never finish (crash leftover, orphan of a gone endpoint) would pin the mark forever.
     */
    private function purgeDeliveries(int $started): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('furan_formbuilder_webhook_delivery');
        $cutoff = gmdate('Y-m-d H:i:s', $started - $this->retentionDays() * 86400);
        $stuckBefore = min($cutoff, gmdate('Y-m-d H:i:s', $started - DeliveryPolicy::WINDOW - 86400));
        $floor = $after = max(0, (int)$this->flagManager->getFlagData(self::FLAG_CODE));
        $keptSeen = false;
        while ($this->now() - $started < self::BUDGET_SECONDS) {
            $rows = $connection->fetchAll(
                'SELECT delivery_id, status, created_at FROM ' . $table
                . ' WHERE delivery_id > ? ORDER BY delivery_id LIMIT ' . self::DELIVERY_BATCH,
                [$after]
            );
            $ids = [];
            $reachedCutoff = false;
            foreach ($rows as $row) {
                if ((string)$row['created_at'] >= $cutoff) {
                    $reachedCutoff = true;
                    break;
                }
                $id = $after = (int)$row['delivery_id'];
                if (in_array((int)$row['status'], DeliveryStatus::FINAL, true) || (string)$row['created_at'] < $stuckBefore) {
                    $ids[] = $id;
                    $floor = $keptSeen ? $floor : $id;
                } else {
                    $keptSeen = true;
                }
            }
            if ($ids !== []) {
                $connection->delete($table, ['delivery_id IN (?)' => $ids]);
            }
            $this->flagManager->saveFlag(self::FLAG_CODE, $floor);
            if ($reachedCutoff || count($rows) < self::DELIVERY_BATCH) {
                return;
            }
        }
    }

    /**
     * Soft-deleted endpoints (a handful of rows, so no index is needed): their deliveries go in primary key batches
     * found through (endpoint_id, delivery_id) with a delivery_id cursor, and the endpoint row only when none are
     * left. It runs before the delivery phase on its own ENDPOINT_BUDGET_SECONDS share. Audit rows stay.
     */
    private function purgeDeletedEndpoints(int $started): void
    {
        $connection = $this->resource->getConnection();
        $endpoints = $this->resource->getTableName('furan_formbuilder_webhook');
        $deliveries = $this->resource->getTableName('furan_formbuilder_webhook_delivery');
        $endpointIds = array_column($connection->fetchAll(
            'SELECT endpoint_id FROM ' . $endpoints . ' WHERE purge_requested_at IS NOT NULL ORDER BY endpoint_id LIMIT '
            . self::ENDPOINT_PAGE
        ), 'endpoint_id');
        foreach ($endpointIds as $endpointId) {
            $after = 0;
            do {
                if ($this->now() - $started >= self::ENDPOINT_BUDGET_SECONDS) {
                    return;
                }
                $ids = array_map('intval', array_column($connection->fetchAll(
                    'SELECT delivery_id FROM ' . $deliveries . ' WHERE endpoint_id = ? AND delivery_id > ?'
                    . ' ORDER BY delivery_id LIMIT ' . self::DELIVERY_BATCH,
                    [(int)$endpointId, $after]
                ), 'delivery_id'));
                if ($ids !== []) {
                    $connection->delete($deliveries, ['delivery_id IN (?)' => $ids]);
                    $after = end($ids);
                }
            } while ($ids !== []);
            $connection->delete($endpoints, ['endpoint_id = ?' => (int)$endpointId, 'purge_requested_at IS NOT NULL']);
        }
    }

    private function retentionDays(): int
    {
        $days = (int)$this->scopeConfig->getValue(self::RETENTION_PATH, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);

        return $days < 1 ? self::DEFAULT_DAYS : min($days, self::MAX_DAYS);
    }

    private function now(): int
    {
        return (int)($this->clock)();
    }
}
