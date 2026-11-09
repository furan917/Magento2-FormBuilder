<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

/**
 * The one entry the submission write paths use to enqueue webhooks. record() and recordMany() run inside the
 * caller's transaction, wake() after it committed. Nothing is enqueued while the env.php kill switch is off.
 * Not for long-running consumers: the endpoint resolver behind the outbox memoises per instance.
 */
class SubmissionDeliveries
{
    public function __construct(
        private readonly Outbox $outbox,
        private readonly UrlPolicy $policy
    ) {
    }

    /**
     * @param array<string, mixed> $eventData
     * @return int[] delivery ids
     */
    public function record(int $submissionId, int $formId, ?int $storeId, string $event, array $eventData = []): array
    {
        if (!$this->policy->isEnabled()) {
            return [];
        }

        return $this->outbox->record($submissionId, $formId, $storeId, $event, $eventData);
    }

    /**
     * @param array<int, array{submissionId: int, formId: int, storeId: ?int, eventData: array<string, mixed>}> $items
     * @return int[] delivery ids
     */
    public function recordMany(string $event, array $items): array
    {
        if (!$this->policy->isEnabled()) {
            return [];
        }

        return $this->outbox->recordMany($event, $items);
    }

    /** @param int[] $ids */
    public function wake(array $ids): void
    {
        if ($ids !== []) {
            $this->outbox->wake($ids);
        }
    }
}
