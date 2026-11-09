<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

/** Counters and the skip set of one drain run. */
class DrainRun
{
    private int $sent = 0;

    private int $slotMisses = 0;

    /** @var array<int, true> */
    private array $skipped = [];

    public function __construct(
        private readonly int $started,
        private readonly int $maxSends,
        private readonly int $maxSlotMisses,
        private readonly int $budgetSeconds
    ) {
    }

    public function spent(int $now): bool
    {
        return $this->sent >= $this->maxSends
            || $this->slotMisses >= $this->maxSlotMisses
            || $now - $this->started >= $this->budgetSeconds;
    }

    public function countSend(): void
    {
        $this->sent++;
    }

    /** An endpoint with both slots taken is skipped for the rest of the run, so it costs one miss. */
    public function countSlotMiss(int $endpointId): void
    {
        $this->slotMisses++;
        $this->skip($endpointId);
    }

    public function skip(int $endpointId): void
    {
        $this->skipped[$endpointId] = true;
    }

    public function isSkipped(int $endpointId): bool
    {
        return isset($this->skipped[$endpointId]);
    }
}
