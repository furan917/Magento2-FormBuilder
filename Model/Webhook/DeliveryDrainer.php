<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Cron fallback for lost wake-ups and due retries. Endpoints are served round-robin, a few rows each per pass,
 * so one dead endpoint cannot starve the rest. Pending rows first, then expired leases, both seeks on the
 * (status, endpoint_id, next_attempt_at, delivery_id) index. A run is bounded by caps and a seconds budget.
 */
class DeliveryDrainer
{
    public const LOCK_NAME = 'furanform_webhook_drain';
    public const PER_ENDPOINT = 20;
    public const CANCEL_BATCH = 500;
    public const MAX_SENDS = 200;
    public const MAX_SLOT_MISSES = 200;
    public const BUDGET_SECONDS = 50;

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly DeliveryClaim $claim,
        private readonly DeliveryRunner $runner,
        private readonly EndpointRepository $endpoints,
        private readonly UrlPolicy $urlPolicy,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger,
        ?callable $clock = null,
        private readonly int $perEndpoint = self::PER_ENDPOINT,
        private readonly int $maxSends = self::MAX_SENDS,
        private readonly int $maxSlotMisses = self::MAX_SLOT_MISSES
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function drain(): void
    {
        if (!$this->urlPolicy->isEnabled() || !$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return;
        }
        try {
            $started = $this->now();
            $run = new DrainRun($started, $this->maxSends, $this->maxSlotMisses, self::BUDGET_SECONDS);
            $states = $this->endpoints->routingStates();
            foreach ([DeliveryStatus::PENDING, DeliveryStatus::DELIVERING] as $status) {
                if ($run->spent($this->now())) {
                    break;
                }
                $this->scan($status, $states, $run);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Webhook drain failed.', ['exception_class' => $e::class]);
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /**
     * @param array<int, array{active: bool, circuit_open_until: ?string}> $states
     */
    private function scan(int $status, array $states, DrainRun $run): void
    {
        $lanes = $this->lanes($status, $states, $run);
        do {
            $progress = false;
            foreach ($lanes as $lane) {
                if ($run->spent($this->now())) {
                    return;
                }
                if (!$lane->done && !$run->isSkipped($lane->endpointId)) {
                    $progress = $this->turn($status, $lane, $run) || $progress;
                }
            }
        } while ($progress);
    }

    /**
     * Circuit-open endpoints get no lane: their rows stay untouched until the circuit closes. Inactive endpoints
     * cancel their pending backlog first (a test send is never cancelled).
     *
     * @param array<int, array{active: bool, circuit_open_until: ?string}> $states
     * @return DrainLane[]
     */
    private function lanes(int $status, array $states, DrainRun $run): array
    {
        $lanes = [];
        foreach ($states as $endpointId => $state) {
            if ($run->isSkipped($endpointId)) {
                continue;
            }
            $circuitOpen = $state['circuit_open_until'] !== null
                && strtotime($state['circuit_open_until'] . ' UTC') > $this->now();
            if (!$circuitOpen) {
                $lanes[] = new DrainLane($endpointId, !$state['active'] && $status === DeliveryStatus::PENDING);
            }
        }

        return $lanes;
    }

    /** @return bool true when this turn did any work */
    private function turn(int $status, DrainLane $lane, DrainRun $run): bool
    {
        $progress = false;
        if ($lane->cancelling) {
            $cancelled = $this->claim->cancelPending($lane->endpointId, self::CANCEL_BATCH);
            $progress = $cancelled > 0;
            if ($cancelled >= self::CANCEL_BATCH) {
                return $progress;
            }
            // Fewer than a batch left: only test rows remain in the pending range, so a plain seek is safe.
            $lane->cancelling = false;
        }
        $rows = $this->claim->dueForEndpoint($status, $lane->endpointId, $this->now(), $this->perEndpoint, $lane->after);
        $lane->done = count($rows) < $this->perEndpoint;
        foreach ($rows as $row) {
            if ($run->spent($this->now()) || $run->isSkipped($lane->endpointId)) {
                break;
            }
            $lane->after = [$row['next_attempt_at'], $row['delivery_id']];
            match ($this->runner->run($row['delivery_id'], $lane->endpointId, $this->now())) {
                RunOutcome::Handled => $run->countSend(),
                RunOutcome::SlotBusy => $run->countSlotMiss($lane->endpointId),
                RunOutcome::Skipped => null,
            };
        }

        return $progress || $rows !== [];
    }

    private function now(): int
    {
        return (int)($this->clock)();
    }
}
