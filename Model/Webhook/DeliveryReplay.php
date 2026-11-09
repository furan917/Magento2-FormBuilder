<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Furan\FormBuilder\Model\Http\UnsafeUrlException;
use Furan\FormBuilder\Model\Spam\RateLimiter;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Redeliver and Resend from the delivery log. Both write a new delivery row (the original is never changed) that
 * the worker builds from the submission's current data, then wake the worker.
 */
class DeliveryReplay
{
    public const LIMIT = 30;
    public const WINDOW_SECONDS = 600;

    public function __construct(
        private readonly DeliveryStore $store,
        private readonly EndpointRepository $repository,
        private readonly Outbox $outbox,
        private readonly UrlPolicy $policy,
        private readonly RateLimiter $rateLimiter,
        private readonly DeliveryLog $log
    ) {
    }

    /**
     * @param bool $newId true = Resend with a new webhook id, false = Redeliver with the original one
     * @param bool $mayReplayPersonal whether the admin holds the personal data permission
     * @return int the new delivery id
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function replay(int $deliveryId, int $adminId, bool $newId, bool $mayReplayPersonal): int
    {
        if (!$this->policy->isEnabled()) {
            throw new LocalizedException(__('Webhooks are switched off in app/etc/env.php.'));
        }
        $original = $this->store->load($deliveryId);
        if ($original === null) {
            throw new NoSuchEntityException(__('The delivery no longer exists.'));
        }
        if ($original['event'] === Outbox::TEST_EVENT) {
            throw new LocalizedException(__('A test event cannot be replayed. Send a new test event instead.'));
        }
        $endpoint = $this->repository->getView((int)$original['endpoint_id']);
        if ($endpoint->includePersonal() && !$mayReplayPersonal) {
            throw new LocalizedException(__('Only admins who may send personal data can replay to a personal data endpoint.'));
        }
        if (!$endpoint->isActive()) {
            throw new LocalizedException(__('The endpoint is inactive. Activate it before replaying.'));
        }
        try {
            $this->policy->validateForSend($endpoint->url());
        } catch (UnsafeUrlException $e) {
            throw new LocalizedException(__('The endpoint URL no longer passes the URL policy: %1', $e->getMessage()), $e);
        }
        if (!$this->log->submissionExists((int)$original['submission_id'])) {
            throw new LocalizedException(__('The submission no longer exists.'));
        }
        if (!$this->rateLimiter->hit('webhook_replay_' . $adminId, self::LIMIT, self::WINDOW_SECONDS)) {
            throw new LocalizedException(__('Too many replays. Try again in a few minutes.'));
        }
        $id = $this->outbox->recordReplay($deliveryId, $adminId, $newId);
        $this->outbox->wake([$id]);

        return $id;
    }
}
