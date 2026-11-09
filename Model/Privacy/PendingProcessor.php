<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Privacy;

use Furan\FormBuilder\Model\Submission\BatchDeleter;
use Furan\FormBuilder\Model\Throttle\Counter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Notification\NotifierInterface;
use Psr\Log\LoggerInterface;

/**
 * Works off furan_formbuilder_privacy_pending, the durable list of a deleted account's submissions. A batch's
 * pending rows go only with its successful erase or anonymise; a failed batch is retried with backoff.
 * Logs name the customer id and a count, never submission ids.
 */
class PendingProcessor
{
    public const LOCK_NAME = 'furanform_privacy_pending';
    public const NOTICE_ATTEMPTS = 10;
    private const NOTICE_KEY = 'ffb_privacy_pending_notice';
    /** Seconds before the next attempt, by attempts made; later attempts use the last. */
    private const BACKOFF = [1 => 60, 2 => 300, 3 => 1800, 4 => 7200, 5 => 21600];

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly BatchDeleter $batchDeleter,
        private readonly Anonymiser $anonymiser,
        private readonly LockManagerInterface $lockManager,
        private readonly NotifierInterface $notifier,
        private readonly Counter $counter,
        private readonly LoggerInterface $logger,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** One deleted account's due rows. With the lock taken the sweep is running and reaches them. */
    public function processCustomer(int $customerId): void
    {
        $this->locked(function () use ($customerId): void {
            $now = gmdate('Y-m-d H:i:s', (int)($this->clock)());
            $after = 0;
            do {
                $rows = $this->due(
                    'p.customer_id = ? AND p.submission_id > ? AND p.next_attempt_at <= ?',
                    [$customerId, $after, $now],
                    'p.submission_id'
                );
                if ($rows === []) {
                    break;
                }
                $this->processBatch($rows, $now);
                $after = (int)end($rows)['submission_id'];
            } while (count($rows) >= BatchDeleter::BATCH_SIZE);
        });
    }

    /** Due rows of every account in due order, within the time budget. */
    public function drain(int $budgetSeconds): void
    {
        $this->locked(function () use ($budgetSeconds): void {
            $start = ($this->clock)();
            $deadline = $start + $budgetSeconds;
            $now = gmdate('Y-m-d H:i:s', (int)$start);
            $after = null;
            while (($this->clock)() < $deadline) {
                $where = 'p.next_attempt_at <= ?';
                $bind = [$now];
                if ($after !== null) {
                    $where .= ' AND (p.next_attempt_at > ? OR (p.next_attempt_at = ? AND p.submission_id > ?))';
                    array_push($bind, $after[0], $after[0], $after[1]);
                }
                $rows = $this->due($where, $bind, 'p.next_attempt_at, p.submission_id');
                if ($rows === []) {
                    break;
                }
                $this->processBatch($rows, $now);
                $last = end($rows);
                $after = [(string)$last['next_attempt_at'], (int)$last['submission_id']];
                if (count($rows) < BatchDeleter::BATCH_SIZE) {
                    break;
                }
            }
        });
    }

    private function locked(callable $work): void
    {
        try {
            if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
                return;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Form Builder privacy queue: ' . $e->getMessage(), ['exception' => $e]);
            return;
        }
        try {
            $work();
        } catch (\Throwable $e) {
            $this->logger->error('Form Builder privacy queue stopped: ' . $e->getMessage(), ['exception' => $e]);
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /**
     * @param array<int, int|string> $bind
     * @return array<int, array<string, mixed>>
     */
    private function due(string $where, array $bind, string $order): array
    {
        return $this->resource->getConnection()->fetchAll(
            'SELECT p.submission_id, p.customer_id, p.mode, p.attempts, p.next_attempt_at, s.customer_id AS owner FROM '
            . $this->resource->getTableName('furan_formbuilder_privacy_pending') . ' p JOIN '
            . $this->resource->getTableName('furan_formbuilder_submission') . ' s ON s.submission_id = p.submission_id'
            . ' WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . BatchDeleter::BATCH_SIZE,
            $bind
        );
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function processBatch(array $rows, string $now): void
    {
        $relinked = [];
        $groups = [];
        foreach ($rows as $row) {
            if ($row['owner'] !== null) {
                $relinked[] = (int)$row['submission_id'];
                continue;
            }
            $groups[(int)$row['customer_id'] . ':' . $row['mode']][] = $row;
        }
        if ($relinked !== []) {
            $this->clear($relinked);
        }
        foreach ($groups as $group) {
            $ids = array_map(static fn (array $row): int => (int)$row['submission_id'], $group);
            try {
                $this->run((string)$group[0]['mode'], $ids);
            } catch (\Throwable $e) {
                $this->failed((int)$group[0]['customer_id'], $group, $e, $now);
            }
        }
    }

    /** @param int[] $ids */
    private function run(string $mode, array $ids): void
    {
        if ($mode === 'delete') {
            // The FK cascade removes the pending rows with the submissions.
            $this->batchDeleter->deleteBatch($ids);
            return;
        }
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $this->anonymiser->anonymise($ids);
            $this->clear($ids);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /** @param int[] $ids */
    private function clear(array $ids): void
    {
        $this->resource->getConnection()->delete(
            $this->resource->getTableName('furan_formbuilder_privacy_pending'),
            ['submission_id IN (?)' => $ids]
        );
    }

    /** @param array<int, array<string, mixed>> $group */
    private function failed(int $customerId, array $group, \Throwable $error, string $now): void
    {
        $this->logger->error(
            sprintf('Form Builder: could not process %d submissions of deleted customer %d: %s', count($group), $customerId, $error->getMessage()),
            ['exception' => $error]
        );
        $byAttempts = [];
        $most = 0;
        foreach ($group as $row) {
            $attempts = (int)$row['attempts'] + 1;
            $byAttempts[$attempts][] = (int)$row['submission_id'];
            $most = max($most, $attempts);
        }
        try {
            foreach ($byAttempts as $attempts => $ids) {
                $this->resource->getConnection()->update(
                    $this->resource->getTableName('furan_formbuilder_privacy_pending'),
                    [
                        'attempts' => $attempts,
                        'next_attempt_at' => gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') + self::BACKOFF[min($attempts, count(self::BACKOFF))]),
                    ],
                    ['submission_id IN (?)' => $ids]
                );
            }
            if ($most >= self::NOTICE_ATTEMPTS && $this->counter->hit(self::NOTICE_KEY, 86400) === 1) {
                $this->notifier->addMajor(
                    (string)__('Deleted customer data is waiting to be processed'),
                    (string)__(
                        'Submissions of deleted customer accounts could not be erased or anonymised after %1 attempts. Form Builder retries them every 6 hours. The exception log has the error.',
                        self::NOTICE_ATTEMPTS
                    )
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('Form Builder: could not record a failed privacy batch: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
