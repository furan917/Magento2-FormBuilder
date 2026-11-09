<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Cron;

use Furan\FormBuilder\Model\Privacy\PendingProcessor;

/** Fallback for a lost wake-up, a failed publish or a privacy consumer that does not run. */
class PrivacyPending
{
    public const BUDGET_SECONDS = 240;

    public function __construct(private readonly PendingProcessor $processor)
    {
    }

    public function execute(): void
    {
        $this->processor->drain(self::BUDGET_SECONDS);
    }
}
