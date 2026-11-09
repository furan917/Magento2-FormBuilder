<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Handler of the furan_formbuilder.webhook queue. The message only wakes: the delivery table is the source of
 * truth, so lost messages are covered by the cron drain. Never throws, because the MySQL queue requeues a
 * thrown message immediately.
 */
class WakeConsumer
{
    public const MAX_IDS = 100;
    public const BUDGET_SECONDS = 50;

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly DeliveryClaim $claim,
        private readonly DeliveryRunner $runner,
        private readonly UrlPolicy $urlPolicy,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function process(string $message): void
    {
        try {
            if (!$this->urlPolicy->isEnabled()) {
                return;
            }
            $ids = $this->ids($message);
            if ($ids === []) {
                return;
            }
            $started = (int)($this->clock)();
            $endpoints = $this->claim->endpointsOf($ids);
            foreach ($ids as $id) {
                $now = (int)($this->clock)();
                if ($now - $started >= self::BUDGET_SECONDS) {
                    return;
                }
                if (isset($endpoints[$id])) {
                    $this->runOne($id, $endpoints[$id], $now);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Webhook wake-up failed.', ['exception_class' => $e::class]);
        }
    }

    private function runOne(int $deliveryId, int $endpointId, int $now): void
    {
        try {
            $this->runner->run($deliveryId, $endpointId, $now);
        } catch (\Throwable $e) {
            $this->logger->error('Webhook delivery failed.', [
                'exception_class' => $e::class,
                'delivery_id' => $deliveryId,
            ]);
        }
    }

    /** @return int[] */
    private function ids(string $message): array
    {
        try {
            $data = $this->json->unserialize($message);
        } catch (\InvalidArgumentException) {
            $data = null;
        }
        $ids = is_array($data) ? ($data['ids'] ?? null) : null;
        if (!is_array($ids) || $ids === [] || count($ids) > self::MAX_IDS) {
            $this->logger->warning('Webhook wake-up message dropped: malformed.');
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    }
}
