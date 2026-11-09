<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Furan\FormBuilder\Api\Data\HttpResult;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Api\WebhookTransportInterface;
use Furan\FormBuilder\Model\Email\StoreScope;
use Furan\FormBuilder\Model\Http\UnsafeUrlException;
use Furan\FormBuilder\Model\Throttle\Counter;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends one claimed delivery end to end. Never throws: an HTTP failure is a scheduled retry, an internal failure
 * is recorded on the row, and the runner always gets control back.
 */
class DeliverySender
{
    /** Error classes that say nothing about the receiver's health, so they never feed its failure streak. */
    private const PROCEED = 'proceed';
    private const VETOED = 'vetoed';
    private const OBSERVER_FAILED = 'observer_failed';
    private const BLOCKED_NOTICE_KEY = 'webhook_blocked:';
    private const CURLE_COULDNT_CONNECT = 7;
    private const NEVER_SENT = [
        HttpResult::ERROR_DNS,
        HttpResult::ERROR_BLOCKED,
        HttpResult::ERROR_TLS,
        HttpResult::ERROR_UNSUPPORTED,
    ];

    private const NOT_THE_ENDPOINTS_FAULT = [
        DeliveryStatus::ERROR_BLOCKED,
        DeliveryStatus::ERROR_PAYLOAD_TOO_LARGE,
        DeliveryStatus::ERROR_SECRET_UNAVAILABLE,
        DeliveryStatus::ERROR_UNSUPPORTED,
        DeliveryStatus::ERROR_ENDPOINT_GONE,
        DeliveryStatus::ERROR_EXPIRED,
        DeliveryStatus::ERROR_OBSERVER,
    ];

    public function __construct(
        private readonly DeliveryStore $deliveries,
        private readonly EndpointRepository $endpoints,
        private readonly EndpointState $state,
        private readonly SubmissionRepositoryInterface $submissions,
        private readonly StoreManagerInterface $storeManager,
        private readonly StoreScope $storeScope,
        private readonly PayloadBuilder $payloads,
        private readonly Secrets $secrets,
        private readonly Signer $signer,
        private readonly UrlPolicy $urlPolicy,
        private readonly WebhookTransportInterface $transport,
        private readonly AttemptRecorder $attempts,
        private readonly RecipientRecorder $recipients,
        private readonly DeliveryPolicy $policy,
        private readonly Audit $audit,
        private readonly NotifierInterface $notifier,
        private readonly Counter $counter,
        private readonly ManagerInterface $events,
        private readonly LoggerInterface $logger
    ) {
    }

    public function send(int $deliveryId, int $now): void
    {
        $endpointId = 0;
        try {
            $delivery = $this->deliveries->load($deliveryId);
            if ($delivery === null) {
                return;
            }
            $endpointId = (int)$delivery['endpoint_id'];
            $this->deliver($deliveryId, $delivery, $now);
        } catch (\Throwable $e) {
            // Class and ids only: the message can carry the URL.
            $this->logger->error('Webhook delivery failed unexpectedly.', [
                'exception_class' => $e::class,
                'endpoint_id' => $endpointId,
                'delivery_id' => $deliveryId,
            ]);
            $this->safely(fn () => $this->deliveries->update($deliveryId, [
                'status' => DeliveryStatus::FAILED,
                'last_error' => DeliveryStatus::ERROR_OBSERVER,
            ]), $endpointId, $deliveryId);
        }
    }

    /**
     * @param array<string, mixed> $delivery
     */
    private function deliver(int $deliveryId, array $delivery, int $now): void
    {
        $isTest = $delivery['event'] === Outbox::TEST_EVENT;
        try {
            $endpoint = $this->endpoints->getView((int)$delivery['endpoint_id']);
        } catch (NoSuchEntityException) {
            $this->cancel($deliveryId, DeliveryStatus::ERROR_ENDPOINT_INACTIVE);
            return;
        }
        if (!$isTest && !$endpoint->isActive()) {
            $this->cancel($deliveryId, DeliveryStatus::ERROR_ENDPOINT_INACTIVE);
            return;
        }
        $circuitOpenUntil = $endpoint->circuitOpenUntil();
        if ($circuitOpenUntil !== null && strtotime($circuitOpenUntil . ' UTC') > $now) {
            $this->deliveries->update($deliveryId, [
                'status' => DeliveryStatus::PENDING,
                'next_attempt_at' => $circuitOpenUntil,
            ]);
            return;
        }
        $submission = null;
        if (!$isTest) {
            try {
                $submission = $this->submissions->getById((int)$delivery['submission_id']);
            } catch (NoSuchEntityException) {
                $this->cancel($deliveryId, null);
                return;
            }
            if (!$this->subscribed($endpoint, (string)$delivery['event'], $submission->getFormId(), $submission->getStoreId())) {
                $this->cancel($deliveryId, DeliveryStatus::ERROR_NOT_SUBSCRIBED);
                return;
            }
        }
        $expired = $this->policy->expiry((string)$delivery['created_at'], $now);
        if ($expired !== null) {
            $this->deliveries->update($deliveryId, ['status' => $expired->status, 'last_error' => $expired->errorClass]);
            return;
        }
        try {
            $url = $this->urlPolicy->validateForSend($endpoint->url());
        } catch (UnsafeUrlException) {
            $this->deliveries->update($deliveryId, [
                'status' => DeliveryStatus::BLOCKED,
                'last_error' => DeliveryStatus::ERROR_BLOCKED,
            ]);
            $this->notifyBlocked($endpoint);
            return;
        }

        try {
            $envelope = $this->payload($endpoint, $delivery, $submission);
        } catch (PayloadTooLargeException) {
            $this->failFinally($deliveryId, $delivery, DeliveryStatus::ERROR_PAYLOAD_TOO_LARGE, $now);
            return;
        } catch (\JsonException) {
            $this->failFinally($deliveryId, $delivery, DeliveryStatus::ERROR_UNSUPPORTED, $now);
            return;
        }

        $verdict = $this->askObservers($endpoint, $delivery, $envelope);
        if ($verdict === self::VETOED) {
            $this->cancel($deliveryId, DeliveryStatus::ERROR_VETOED);
            return;
        }
        if ($verdict === self::OBSERVER_FAILED) {
            $this->recordLocalAttempt($deliveryId, $now, DeliveryStatus::ERROR_OBSERVER);
            $this->settle($deliveryId, $delivery, $endpoint, new Outcome(0, DeliveryStatus::ERROR_OBSERVER, false, null), $now);
            return;
        }

        try {
            $headers = $this->signedHeaders($endpoint, (string)$delivery['webhook_id'], $envelope, $now);
        } catch (SecretUnavailableException) {
            $this->failFinally($deliveryId, $delivery, DeliveryStatus::ERROR_SECRET_UNAVAILABLE, $now);
            $this->disable($endpoint, 'secret_unavailable');
            return;
        }

        $result = $this->transport->send($url, $this->urlPolicy->policy(), $headers, $envelope->json);
        $this->attempts->record(
            $deliveryId,
            gmdate('Y-m-d H:i:s', $now),
            $result->durationMs,
            $result->status > 0 ? $result->status : null,
            $result->errorClass,
            $result->errno > 0 ? $result->errno : null,
            $result->connectedIp,
            $result->headers['location'] ?? null,
            $this->policy->retryAfter($result->headers['retry-after'] ?? null, $now),
            $endpoint->debugResponses() && $result->body !== '' ? $result->body : null
        );
        $this->settle(
            $deliveryId,
            $delivery,
            $endpoint,
            new Outcome($result->status, $result->errorClass, $result->bodyTruncated, $result->headers['retry-after'] ?? null),
            $now
        );
        if ($delivery['submission_id'] !== null && $this->bodyMayHaveArrived($result)) {
            $this->safely(fn () => $this->recipients->record(
                (int)$delivery['submission_id'],
                $endpoint->id(),
                $url->host,
                gmdate('Y-m-d H:i:s', $now)
            ), $endpoint->id(), $deliveryId);
        }
    }

    /** Any answer means the request went out; without one, only a failure after connect may have carried it. */
    private function bodyMayHaveArrived(HttpResult $result): bool
    {
        if ($result->status > 0) {
            return true;
        }

        return $result->connectedIp !== null
            && $result->errno !== self::CURLE_COULDNT_CONNECT
            && !in_array($result->errorClass, self::NEVER_SENT, true);
    }

    /**
     * Observers get a copy of the decoded envelope and an endpoint view without secrets; the signed bytes are
     * the builder's string, so nothing an observer does changes them. A throwing observer is a retry.
     *
     * @param array<string, mixed> $delivery
     */
    private function askObservers(EndpointView $endpoint, array $delivery, Envelope $envelope): string
    {
        $veto = new DataObject();
        $decoded = json_decode($envelope->json, true);
        try {
            $this->events->dispatch('furan_formbuilder_webhook_send_before', [
                'endpoint' => $endpoint,
                'event' => (string)$delivery['event'],
                'delivery_id' => (int)$delivery['delivery_id'],
                'envelope' => new DataObject(is_array($decoded) ? $decoded : []),
                'veto' => $veto,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Webhook send observer failed.', [
                'exception_class' => $e::class,
                'endpoint_id' => $endpoint->id(),
                'delivery_id' => (int)$delivery['delivery_id'],
            ]);
            return self::OBSERVER_FAILED;
        }

        return $veto->getData('veto') === true ? self::VETOED : self::PROCEED;
    }

    /**
     * @param array<string, mixed> $delivery
     */
    private function settle(int $deliveryId, array $delivery, EndpointView $endpoint, Outcome $outcome, int $now): void
    {
        $attempt = (int)$delivery['attempts'] + 1;
        $decision = $this->policy->decide(
            $outcome,
            $attempt,
            (string)$delivery['created_at'],
            ['failing_since' => $endpoint->failingSince(), 'consecutive_failures' => $endpoint->consecutiveFailures()],
            $now
        );
        $bind = [
            'status' => $decision->status,
            'attempts' => $attempt,
            'last_status_code' => $outcome->status > 0 ? $outcome->status : null,
            'last_error' => $decision->errorClass,
            'delivered_at' => $decision->status === DeliveryStatus::SUCCEEDED ? gmdate('Y-m-d H:i:s', $now) : null,
        ];
        if ($decision->nextAttemptAt !== null) {
            $bind['next_attempt_at'] = $decision->nextAttemptAt;
        }
        $this->deliveries->update($deliveryId, $bind);
        $this->updateEndpoint($endpoint, $decision, $now);
    }

    /**
     * @param array<string, mixed> $delivery
     * @throws PayloadTooLargeException
     * @throws \JsonException
     */
    private function payload(EndpointView $endpoint, array $delivery, ?SubmissionInterface $submission): Envelope
    {
        $build = fn (): Envelope => $this->payloads->build(
            $endpoint,
            (string)$delivery['webhook_id'],
            (string)$delivery['event'],
            $this->occurredAt((string)$delivery['created_at']),
            $submission,
            $this->eventData($delivery['event_data'] ?? null)
        );

        return $submission === null || (int)$submission->getStoreId() < 1
            ? $build()
            : $this->storeScope->run((int)$submission->getStoreId(), $build);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventData(mixed $stored): array
    {
        if (!is_string($stored) || $stored === '') {
            return [];
        }
        $data = json_decode($stored, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, string>
     * @throws SecretUnavailableException
     */
    private function signedHeaders(EndpointView $endpoint, string $msgId, Envelope $envelope, int $now): array
    {
        $signing = $this->endpoints->getForSigning($endpoint->id());

        return $this->signer->headers(
            $msgId,
            $now,
            $envelope->json,
            $this->secrets->current($signing),
            $this->secrets->previous($signing),
            $endpoint->previousExpiresAt(),
            $now,
            $endpoint->id()
        ) + ['Content-Type' => 'application/json', 'User-Agent' => 'Furan-FormBuilder-Webhooks/1'];
    }

    /**
     * A failure that no retry can fix and that says nothing about the receiver: the attempt is logged, the
     * endpoint's failure streak is left alone.
     *
     * @param array<string, mixed> $delivery
     */
    private function failFinally(int $deliveryId, array $delivery, string $errorClass, int $now): void
    {
        $this->recordLocalAttempt($deliveryId, $now, $errorClass);
        $this->deliveries->update($deliveryId, [
            'status' => DeliveryStatus::FAILED,
            'attempts' => (int)$delivery['attempts'] + 1,
            'last_error' => $errorClass,
        ]);
    }

    /** An attempt that never reached the network. */
    private function recordLocalAttempt(int $deliveryId, int $now, string $errorClass): void
    {
        $this->attempts->record($deliveryId, gmdate('Y-m-d H:i:s', $now), 0, null, $errorClass, null, null, null, null);
    }

    private function cancel(int $deliveryId, ?string $reason): void
    {
        $this->deliveries->update($deliveryId, ['status' => DeliveryStatus::CANCELLED, 'last_error' => $reason]);
    }

    /** Judged against the submission's own store, like the resolver does at enqueue time. */
    private function subscribed(EndpointView $endpoint, string $event, int $formId, ?int $storeId): bool
    {
        if (!in_array($event, $endpoint->events(), true)
            || !($endpoint->allForms() || in_array($formId, $endpoint->formIds(), true))
        ) {
            return false;
        }
        if ($storeId === null || $storeId < 1) {
            return false;
        }
        try {
            $scopeId = $endpoint->scopeType() === 'website'
                ? (int)$this->storeManager->getStore($storeId)->getWebsiteId()
                : $storeId;
        } catch (NoSuchEntityException) {
            return false;
        }

        return in_array($scopeId, $endpoint->scopeIds(), true);
    }

    private function occurredAt(string $createdAt): string
    {
        return str_replace(' ', 'T', $createdAt) . 'Z';
    }

    private function updateEndpoint(EndpointView $endpoint, Decision $decision, int $now): void
    {
        if ($decision->resetFailures) {
            if ($endpoint->consecutiveFailures() > 0 || $endpoint->failingSince() !== null || $endpoint->circuitOpenUntil() !== null) {
                $this->state->reset($endpoint->id());
            }
            return;
        }
        if ($decision->status === DeliveryStatus::BLOCKED) {
            $this->notifyBlocked($endpoint);
        }
        if ($decision->disableEndpoint !== null) {
            $this->disable($endpoint, $decision->disableEndpoint);
        }
        if ($decision->errorClass !== null && !in_array($decision->errorClass, self::NOT_THE_ENDPOINTS_FAULT, true)) {
            $this->state->recordFailure($endpoint->id(), gmdate('Y-m-d H:i:s', $now), $decision->openCircuitUntil);
        }
    }

    /** Only the caller that really switched the endpoint off audits and notifies. */
    private function disable(EndpointView $endpoint, string $reason): void
    {
        if (!$this->state->disable($endpoint->id(), $reason)) {
            return;
        }
        $this->safely(function () use ($endpoint, $reason): void {
            $this->audit->record($endpoint->id(), null, 'auto_disabled', $reason);
            $this->notifier->addMajor((string)__('Webhook endpoint disabled'), $this->disabledText($endpoint->name(), $reason));
        }, $endpoint->id());
    }

    private function disabledText(string $name, string $reason): string
    {
        return (string)match ($reason) {
            DeliveryPolicy::DISABLE_GONE
                => __('The webhook endpoint "%1" was disabled because the receiver answered 410 Gone.', $name),
            DeliveryPolicy::DISABLE_FAILING
                => __('The webhook endpoint "%1" was disabled after three days of failed deliveries.', $name),
            default
                => __('The webhook endpoint "%1" was disabled because its signing secret cannot be read. Rotate the secret or run furanform:webhooks:reencrypt.', $name),
        };
    }

    /** At most one notice per endpoint per day, so a backlog of blocked rows is not a notice flood. */
    private function notifyBlocked(EndpointView $endpoint): void
    {
        $this->safely(function () use ($endpoint): void {
            if ($this->counter->hit(self::BLOCKED_NOTICE_KEY . $endpoint->id(), 86400) !== 1) {
                return;
            }
            $this->notifier->addMajor(
                (string)__('Webhook delivery blocked'),
                (string)__(
                    'Deliveries to the webhook endpoint "%1" were blocked because its URL no longer passes the security policy.',
                    $endpoint->name()
                )
            );
        }, $endpoint->id());
    }

    /** Side effects after the delivery row is final must not undo it, nor throw. */
    private function safely(callable $work, int $endpointId, int $deliveryId = 0): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            $this->logger->error('Webhook side effect failed.', [
                'exception_class' => $e::class,
                'endpoint_id' => $endpointId,
                'delivery_id' => $deliveryId,
            ]);
        }
    }
}
