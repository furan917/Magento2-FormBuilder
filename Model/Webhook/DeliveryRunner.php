<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Psr\Log\LoggerInterface;

/**
 * Takes an endpoint slot, then claims one delivery and hands it to the sender. Without a free slot the delivery
 * stays unclaimed for the next run. Never throws.
 */
class DeliveryRunner
{
    public function __construct(
        private readonly DeliveryClaim $claim,
        private readonly EndpointSlots $slots,
        private readonly DeliverySender $sender,
        private readonly LoggerInterface $logger
    ) {
    }

    public function run(int $deliveryId, int $endpointId, int $now): RunOutcome
    {
        try {
            $slot = $this->slots->acquire($endpointId, $now);
            if ($slot === null) {
                return $this->missed($deliveryId, $endpointId);
            }
        } catch (\Throwable $e) {
            $this->logFailure($e, $deliveryId, $endpointId);
            return RunOutcome::Skipped;
        }
        try {
            if (!$this->claim->claim($deliveryId, $now)) {
                return RunOutcome::Skipped;
            }
            $this->sender->send($deliveryId, $now);

            return RunOutcome::Handled;
        } catch (\Throwable $e) {
            $this->logFailure($e, $deliveryId, $endpointId);
            return RunOutcome::Skipped;
        } finally {
            $this->release($endpointId, $slot, $now);
        }
    }

    /** No slot: busy, or the endpoint row is gone (a late insert raced the purge), which no sender would see. */
    private function missed(int $deliveryId, int $endpointId): RunOutcome
    {
        if ($this->slots->endpointExists($endpointId)) {
            return RunOutcome::SlotBusy;
        }
        $this->claim->cancel($deliveryId, DeliveryStatus::ERROR_ENDPOINT_INACTIVE);

        return RunOutcome::Handled;
    }

    private function release(int $endpointId, int $slot, int $now): void
    {
        try {
            $this->slots->release($endpointId, $slot, $now);
        } catch (\Throwable $e) {
            // The lease expires by itself.
            $this->logFailure($e, 0, $endpointId);
        }
    }

    private function logFailure(\Throwable $e, int $deliveryId, int $endpointId): void
    {
        $this->logger->error('Webhook delivery runner failed.', [
            'exception_class' => $e::class,
            'endpoint_id' => $endpointId,
            'delivery_id' => $deliveryId,
        ]);
    }
}
