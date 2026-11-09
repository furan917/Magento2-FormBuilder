<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Furan\FormBuilder\Model\Spam\RateLimiter;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/** Roll secret and send test event from the endpoint edit page. Sending itself is the sender's job. */
class EndpointActions
{
    public const MAX_WINDOW_HOURS = 72;
    public const TEST_LIMIT = 10;
    public const TEST_WINDOW_SECONDS = 600;

    public function __construct(
        private readonly EndpointRepository $repository,
        private readonly Outbox $outbox,
        private readonly UrlPolicy $policy,
        private readonly RateLimiter $rateLimiter,
        private readonly Audit $audit
    ) {
    }

    /**
     * @throws NoSuchEntityException when the endpoint is gone or its purge was requested
     */
    public function rotate(int $endpointId, int $windowHours, ?int $adminId): EndpointSecret
    {
        return $this->repository->rotate($endpointId, max(0, min(self::MAX_WINDOW_HOURS, $windowHours)), $adminId);
    }

    /**
     * Allowed for an inactive endpoint on purpose: it is how a fixed URL is checked before re-enabling.
     *
     * @return int the delivery id
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function sendTest(int $endpointId, int $adminId): int
    {
        if (!$this->policy->isEnabled()) {
            throw new LocalizedException(__('Webhooks are switched off in app/etc/env.php.'));
        }
        $endpoint = $this->repository->getView($endpointId);
        if (!$this->rateLimiter->hit(
            sprintf('webhook_test_%d_%d', $adminId, $endpointId),
            self::TEST_LIMIT,
            self::TEST_WINDOW_SECONDS
        )) {
            throw new LocalizedException(__('Too many test events. Try again in a few minutes.'));
        }
        $this->audit->record($endpointId, $adminId, 'test_sent', (string)parse_url($endpoint->url(), PHP_URL_HOST));
        $id = $this->outbox->recordTest($endpointId, $adminId);
        $this->outbox->wake([$id]);

        return $id;
    }
}
