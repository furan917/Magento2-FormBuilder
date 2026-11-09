<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Action;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

/** Runs the retries whose backoff has passed; a failure schedules the next one through the executor. */
class RetryDrainer
{
    public const LOCK_NAME = 'furanform_action_retries';
    public const BATCH_SIZE = 200;

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ActionQueue $queue,
        private readonly ActionConsumer $consumer,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function drain(): void
    {
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return;
        }
        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName(RetryScheduler::TABLE);
            $rows = $connection->fetchAll(
                'SELECT retry_id, submission_id, action_code, attempt, is_edit FROM ' . $table
                . ' WHERE due_at <= ? ORDER BY due_at, retry_id LIMIT ' . self::BATCH_SIZE,
                [gmdate('Y-m-d H:i:s', (int)($this->clock)())]
            );
            foreach ($rows as $row) {
                // Deleted first: a crash mid-run must not run the action twice.
                $connection->delete($table, ['retry_id = ?' => (int)$row['retry_id']]);
                $this->runOne(
                    (int)$row['submission_id'],
                    (string)$row['action_code'],
                    (int)$row['attempt'],
                    (bool)(int)$row['is_edit']
                );
            }
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    private function runOne(int $submissionId, string $actionKey, int $attempt, bool $isEdit): void
    {
        try {
            if (!$this->queue->publish($submissionId, $actionKey, $attempt, $isEdit)) {
                $this->consumer->run($submissionId, $actionKey, $attempt, $isEdit);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Form Builder action retry failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
