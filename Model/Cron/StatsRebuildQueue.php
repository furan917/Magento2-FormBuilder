<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Cron;

use Furan\FormBuilder\Model\Stats\Folder;
use Furan\FormBuilder\Model\Stats\RebuildQueue;
use Furan\FormBuilder\Model\Stats\Rebuilder;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

class StatsRebuildQueue
{
    private const BUDGET_SECONDS = 10800;

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly RebuildQueue $queue,
        private readonly Rebuilder $rebuilder,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function execute(): void
    {
        $start = ($this->clock)();
        foreach ($this->queue->queued() as $formId) {
            if (($this->clock)() - $start >= self::BUDGET_SECONDS) {
                return;
            }
            if ($this->lockManager->isLocked(Folder::REBUILD_LOCK_PREFIX . $formId)) {
                continue;
            }
            // Cleared first: a rebuild that fails keeps needs_rebuild and waits for an admin, not for tomorrow night.
            $this->queue->release($formId);
            try {
                $this->rebuilder->rebuild($formId);
            } catch (\Throwable $e) {
                $this->logger->error('Queued stats rebuild failed for form ' . $formId . ': ' . $e->getMessage());
            }
        }
    }
}
