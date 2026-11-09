<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Action;

use Furan\FormBuilder\Api\ActionRetrySchedulerInterface;
use Magento\Framework\App\ResourceConnection;

/** Parks a failed retryable action in furan_formbuilder_action_retry until its backoff has passed. */
class RetryScheduler implements ActionRetrySchedulerInterface
{
    public const TABLE = 'furan_formbuilder_action_retry';

    /** Seconds to wait after failed attempt 1, 2, ... */
    private const BACKOFF_SECONDS = [60, 300, 1800, 7200, 21600];

    /** @var callable */
    private $clock;

    public function __construct(private readonly ResourceConnection $resource, ?callable $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    public function schedule(int $submissionId, string $actionKey, int $attempt, string $error, bool $isEdit): void
    {
        $delay = self::BACKOFF_SECONDS[min(max($attempt, 1), count(self::BACKOFF_SECONDS)) - 1];
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            [
                'submission_id' => $submissionId,
                'action_code' => $actionKey,
                'attempt' => $attempt + 1,
                'due_at' => gmdate('Y-m-d H:i:s', (int)($this->clock)() + $delay),
                'last_error' => substr($error, 0, 255),
                'is_edit' => (int)$isEdit,
            ],
            // The consumer reloads the submission, so a create retried after an edit still sends the edited values.
            ['attempt', 'due_at', 'last_error', 'is_edit' => new \Zend_Db_Expr('LEAST(is_edit, VALUES(is_edit))')]
        );
    }
}
