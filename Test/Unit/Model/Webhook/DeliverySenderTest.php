<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\WebhookTransportInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Model\Email\StoreScope;
use Furan\FormBuilder\Api\Data\HttpResult;
use Furan\FormBuilder\Api\Data\RequestPolicy;
use Furan\FormBuilder\Api\Data\SafeUrl;
use Furan\FormBuilder\Model\Throttle\Counter;
use Furan\FormBuilder\Model\Webhook\AttemptRecorder;
use Furan\FormBuilder\Model\Webhook\Audit;
use Furan\FormBuilder\Model\Webhook\DeliveryPolicy;
use Furan\FormBuilder\Model\Webhook\DeliverySender;
use Furan\FormBuilder\Model\Webhook\DeliveryStatus;
use Furan\FormBuilder\Model\Webhook\DeliveryStore;
use Furan\FormBuilder\Model\Webhook\Endpoint;
use Furan\FormBuilder\Model\Webhook\EndpointRepository;
use Furan\FormBuilder\Model\Webhook\EndpointState;
use Furan\FormBuilder\Model\Webhook\EndpointView;
use Furan\FormBuilder\Model\Webhook\Envelope;
use Furan\FormBuilder\Model\Webhook\PayloadBuilder;
use Furan\FormBuilder\Model\Webhook\PayloadTooLargeException;
use Furan\FormBuilder\Model\Webhook\RecipientRecorder;
use Furan\FormBuilder\Model\Webhook\RandomFraction;
use Furan\FormBuilder\Model\Webhook\Secrets;
use Furan\FormBuilder\Model\Webhook\Signer;
use Furan\FormBuilder\Model\Webhook\UrlPolicy;
use Furan\FormBuilder\Test\Unit\Helper\SignatureVerifier;
use Magento\Framework\DataObject;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DeliverySenderTest extends TestCase
{
    private const NOW = 1800000000;
    private const BODY = '{"id":"msg_abc","type":"submission.created"}';

    private string $secret;

    /** @var array<string, mixed> */
    private array $delivery;

    private EndpointView $view;

    private bool $submissionExists = true;

    private int $submissionLoads = 0;

    private bool $endpointGone = false;

    private bool $disableWins = true;

    private bool $noticeFails = false;

    private bool $deliveryLoadFails = false;

    /** @var array<int, array<string, mixed>> */
    private array $logged = [];

    private ?int $submissionStoreId = 1;

    private ?string $decrypted = null;

    /** @var array<int, array{0: int, 1: array<string, mixed>}> */
    private array $deliveryUpdates = [];

    /** @var array<int, array<int, mixed>> */
    private array $attempts = [];

    /** @var array<int, array{0: string, 1: array<string, string>, 2: string}> */
    private array $sent = [];

    /** @var array<int, array{0: string, 1: string}> */
    private array $notices = [];

    /** @var array<int, string> */
    private array $audits = [];

    /** @var array<int, string> */
    private array $disabled = [];

    /** @var array<int, array<int, mixed>> */
    private array $failures = [];

    private int $resets = 0;

    private ?HttpResult $result = null;

    private ?\Throwable $buildThrows = null;

    private bool $urlRefused = false;

    private int $noticeHits = 1;

    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    private array $dispatched = [];

    private ?\Closure $observer = null;

    private ?WebhookTransportInterface $replacedTransport = null;

    /** @var array<int, array<int, mixed>> */
    private array $recipients = [];

    private bool $recipientWriteFails = false;

    /** @var MockObject&UrlPolicy */
    private $urlPolicy;

    protected function setUp(): void
    {
        $this->secret = 'whsec_' . base64_encode(str_repeat('k', 32));
        $this->delivery = [
            'delivery_id' => 11, 'endpoint_id' => 7, 'submission_id' => 21, 'event' => 'submission.created',
            'event_data' => null, 'webhook_id' => 'msg_abc', 'attempts' => 0,
            'created_at' => gmdate('Y-m-d H:i:s', self::NOW - 60), 'next_attempt_at' => gmdate('Y-m-d H:i:s', self::NOW),
        ];
        $this->view = $this->view();
    }

    /** @param array<string, mixed> $o */
    private function view(array $o = []): EndpointView
    {
        $o += [
            'active' => true, 'events' => ['submission.created'], 'formIds' => [5], 'allForms' => false,
            'scopeIds' => [1], 'scopeType' => 'store', 'debug' => false, 'failingSince' => null,
            'failures' => 0, 'circuit' => null,
        ];
        return new EndpointView(
            7, 'CRM', 'https://crm.example.com/hook?token=secret', $o['active'], $o['scopeType'], $o['scopeIds'],
            $o['allForms'], $o['formIds'], $o['events'], 'all', [], false, $o['debug'], null, null,
            $o['failingSince'], $o['failures'], $o['circuit']
        );
    }

    private function sender(): DeliverySender
    {
        $store = $this->createStub(DeliveryStore::class);
        $store->method('load')->willReturnCallback(function (): ?array {
            if ($this->deliveryLoadFails) {
                throw new \RuntimeException('db down');
            }
            return $this->delivery;
        });
        $store->method('update')->willReturnCallback(function (int $id, array $bind): void {
            if ($this->deliveryLoadFails) {
                throw new \RuntimeException('db down');
            }
            $this->deliveryUpdates[] = [$id, $bind];
        });

        $endpoints = $this->createStub(EndpointRepository::class);
        $endpoints->method('getView')->willReturnCallback(function (): EndpointView {
            if ($this->endpointGone) {
                throw new NoSuchEntityException(__('gone'));
            }
            return $this->view;
        });
        $endpoints->method('getForSigning')->willReturn(new Endpoint(7, 'enc', null, null));

        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(fn (): string => $this->decrypted ?? $this->secret);

        $state = $this->createStub(EndpointState::class);
        $state->method('recordFailure')->willReturnCallback(function (...$args): void {
            $this->failures[] = $args;
        });
        $state->method('reset')->willReturnCallback(function (): void {
            $this->resets++;
        });
        $state->method('disable')->willReturnCallback(function (int $id, string $reason): bool {
            $this->disabled[] = $reason;
            return $this->disableWins;
        });

        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getFormId')->willReturn(5);
        $submission->method('getStoreId')->willReturnCallback(fn (): ?int => $this->submissionStoreId);
        $submissions = $this->createStub(SubmissionRepositoryInterface::class);
        $submissions->method('getById')->willReturnCallback(function () use ($submission): SubmissionInterface {
            $this->submissionLoads++;
            if (!$this->submissionExists) {
                throw new NoSuchEntityException(__('gone'));
            }
            return $submission;
        });

        $storeObject = $this->createStub(StoreInterface::class);
        $storeObject->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($storeObject);

        $scope = $this->createStub(StoreScope::class);
        $scope->method('run')->willReturnCallback(static fn (int $storeId, callable $work): mixed => $work());

        $payloads = $this->createStub(PayloadBuilder::class);
        $payloads->method('build')->willReturnCallback(function (): Envelope {
            if ($this->buildThrows !== null) {
                throw $this->buildThrows;
            }
            return new Envelope(self::BODY, strlen(self::BODY));
        });

        $this->urlPolicy = $this->createStub(UrlPolicy::class);
        $this->urlPolicy->method('policy')->willReturn(new RequestPolicy());
        $this->urlPolicy->method('validateForSend')->willReturnCallback(function (): SafeUrl {
            if ($this->urlRefused) {
                throw new \Furan\FormBuilder\Model\Http\UnsafeUrlException('port');
            }
            return new SafeUrl('https', 'crm.example.com', 443, '/hook?token=secret', false);
        });

        $transport = $this->createStub(WebhookTransportInterface::class);
        $transport->method('send')->willReturnCallback(function (SafeUrl $u, RequestPolicy $p, array $headers, string $body): HttpResult {
            $this->sent[] = [$u->toString(), $headers, $body];
            return $this->result ?? new HttpResult(200, [], '', false, null, 0, 35, '203.0.113.9');
        });

        $recorder = $this->createStub(AttemptRecorder::class);
        $recorder->method('record')->willReturnCallback(function (...$args): int {
            $this->attempts[] = $args;
            return 1;
        });

        $recipients = $this->createStub(RecipientRecorder::class);
        $recipients->method('record')->willReturnCallback(function (...$args): void {
            if ($this->recipientWriteFails) {
                throw new \RuntimeException('db down');
            }
            $this->recipients[] = $args;
        });

        $random = $this->createStub(RandomFraction::class);
        $random->method('next')->willReturn(0.5);

        $audit = $this->createStub(Audit::class);
        $audit->method('record')->willReturnCallback(function (int $id, ?int $admin, string $action): void {
            $this->audits[] = $action;
        });

        $notifier = $this->createStub(NotifierInterface::class);
        $notifier->method('addMajor')->willReturnCallback(function ($title, $description): NotifierInterface {
            if ($this->noticeFails) {
                throw new \RuntimeException('notifier down');
            }
            $this->notices[] = [(string)$title, (string)$description];
            return $this->createStub(NotifierInterface::class);
        });

        $counter = $this->createStub(Counter::class);
        $counter->method('hit')->willReturnCallback(fn (): int => $this->noticeHits);

        $events = $this->createStub(ManagerInterface::class);
        $events->method('dispatch')->willReturnCallback(function (string $name, array $data = []): void {
            $this->dispatched[] = [$name, $data];
            if ($this->observer !== null) {
                ($this->observer)($data);
            }
        });

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string $message, array $context = []): void {
            $this->logged[] = $context;
        });

        return new DeliverySender(
            $store,
            $endpoints,
            $state,
            $submissions,
            $storeManager,
            $scope,
            $payloads,
            new Secrets($encryptor),
            new Signer($this->createStub(LoggerInterface::class)),
            $this->urlPolicy,
            $this->replacedTransport ?? $transport,
            $recorder,
            $recipients,
            new DeliveryPolicy($random),
            $audit,
            $notifier,
            $counter,
            $events,
            $logger
        );
    }

    /** @return array<string, mixed> the last update written to the delivery */
    private function lastUpdate(): array
    {
        $this->assertNotSame([], $this->deliveryUpdates);
        return $this->deliveryUpdates[array_key_last($this->deliveryUpdates)][1];
    }

    public function testItSendsTheSignedEnvelopeBytesAndMarksTheDeliverySucceededOn2xx(): void
    {
        $this->sender()->send(11, self::NOW);

        $this->assertCount(1, $this->sent);
        [$url, $headers, $body] = $this->sent[0];
        $this->assertSame('https://crm.example.com/hook?token=secret', $url);
        $this->assertSame(self::BODY, $body);
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame('Furan-FormBuilder-Webhooks/1', $headers['User-Agent']);
        $this->assertSame('msg_abc', $headers['webhook-id']);
        $this->assertTrue((new SignatureVerifier())->verify(
            $this->secret,
            $body,
            $headers['webhook-id'],
            $headers['webhook-timestamp'],
            $headers['webhook-signature'],
            self::NOW
        ));
        $this->assertCount(1, $this->attempts);
        $this->assertSame(11, $this->attempts[0][0]);
        $this->assertSame(200, $this->attempts[0][3]);
        $this->assertNull($this->attempts[0][4]);
        $update = $this->lastUpdate();
        $this->assertSame(DeliveryStatus::SUCCEEDED, $update['status']);
        $this->assertSame(1, $update['attempts']);
        $this->assertSame(gmdate('Y-m-d H:i:s', self::NOW), $update['delivered_at']);
        $this->assertSame(200, $update['last_status_code']);
        $this->assertNull($update['last_error']);
    }

    public function testItRecordsTheAttemptAndSchedulesTheNextOneFromThePolicyAfterAFailure(): void
    {
        $this->result = new HttpResult(503, ['retry-after' => '600', 'location' => 'https://x.test/y'], 'busy', false, null, 0, 80, '203.0.113.9');

        $this->sender()->send(11, self::NOW);

        $this->assertCount(1, $this->attempts);
        $this->assertSame([11, gmdate('Y-m-d H:i:s', self::NOW), 80, 503, null, null, '203.0.113.9', 'https://x.test/y', 600], array_slice($this->attempts[0], 0, 9));
        $update = $this->lastUpdate();
        $this->assertSame(DeliveryStatus::PENDING, $update['status']);
        $this->assertSame(1, $update['attempts']);
        $this->assertSame(gmdate('Y-m-d H:i:s', self::NOW + 600), $update['next_attempt_at']);
        $this->assertSame('http', $update['last_error']);
        $this->assertSame(503, $update['last_status_code']);
        $this->assertSame([[7, gmdate('Y-m-d H:i:s', self::NOW), null]], $this->failures);
        $this->assertSame(0, $this->resets);
    }

    public function testItRecordsTheRecipientOnTheFirstSuccessfulDelivery(): void
    {
        $this->sender()->send(11, self::NOW);

        $this->assertSame([[21, 7, 'crm.example.com', gmdate('Y-m-d H:i:s', self::NOW)]], $this->recipients);
    }

    public function testItRecordsNoRecipientForATestDelivery(): void
    {
        $this->delivery['event'] = 'webhook.test';
        $this->delivery['submission_id'] = null;

        $this->sender()->send(11, self::NOW);

        $this->assertSame(DeliveryStatus::SUCCEEDED, $this->lastUpdate()['status']);
        $this->assertSame([], $this->recipients);
    }

    /**
     * @return array<string, array{HttpResult}>
     */
    public static function sentButUnanswered(): array
    {
        return [
            'http error' => [new HttpResult(503, [], '', false, null, 0, 80, '203.0.113.9')],
            'timeout after connect' => [new HttpResult(0, [], '', false, HttpResult::ERROR_TIMEOUT, 28, 80, '203.0.113.9')],
            'reset after send' => [new HttpResult(0, [], '', false, HttpResult::ERROR_CONNECT, 56, 80, '203.0.113.9')],
            'oversized headers' => [new HttpResult(0, [], '', false, HttpResult::ERROR_RESPONSE_TOO_LARGE, 23, 80, '203.0.113.9')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sentButUnanswered')]
    public function testItRecordsTheRecipientWhenThePayloadWasSentButNotAccepted(HttpResult $result): void
    {
        $this->result = $result;

        $this->sender()->send(11, self::NOW);

        $this->assertNotSame(DeliveryStatus::SUCCEEDED, $this->lastUpdate()['status']);
        $this->assertSame([[21, 7, 'crm.example.com', gmdate('Y-m-d H:i:s', self::NOW)]], $this->recipients);
    }

    /**
     * @return array<string, array{HttpResult}>
     */
    public static function neverSent(): array
    {
        return [
            'dns' => [new HttpResult(0, [], '', false, HttpResult::ERROR_DNS, 6, 5)],
            'refused' => [new HttpResult(0, [], '', false, HttpResult::ERROR_CONNECT, 7, 5)],
            'refused with an address' => [new HttpResult(0, [], '', false, HttpResult::ERROR_CONNECT, 7, 5, '203.0.113.9')],
            'blocked at connect' => [new HttpResult(0, [], '', false, HttpResult::ERROR_BLOCKED, 42, 5, '203.0.113.9')],
            'tls handshake' => [new HttpResult(0, [], '', false, HttpResult::ERROR_TLS, 35, 5, '203.0.113.9')],
            'unsupported' => [new HttpResult(0, [], '', false, HttpResult::ERROR_UNSUPPORTED)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('neverSent')]
    public function testItRecordsNoRecipientWhenNothingWasSent(HttpResult $result): void
    {
        $this->result = $result;

        $this->sender()->send(11, self::NOW);

        $this->assertSame([], $this->recipients);
    }

    public function testItKeepsTheDeliverySucceededWhenTheRecipientWriteFails(): void
    {
        $this->recipientWriteFails = true;

        $this->sender()->send(11, self::NOW);

        $this->assertSame(DeliveryStatus::SUCCEEDED, $this->lastUpdate()['status']);
        $this->assertCount(1, $this->deliveryUpdates);
        $this->assertSame(
            [['exception_class' => \RuntimeException::class, 'endpoint_id' => 7, 'delivery_id' => 11]],
            $this->logged
        );
    }

    public function testItClearsTheFailureStateWhenADeliverySucceedsAfterFailures(): void
    {
        $this->view = $this->view(['failures' => 2, 'failingSince' => '2026-11-07 10:00:00']);

        $this->sender()->send(11, self::NOW);

        $this->assertSame(1, $this->resets);
        $this->assertSame([], $this->failures);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function cancelledEndpoints(): array
    {
        return [
            'inactive' => [['active' => false], DeliveryStatus::ERROR_ENDPOINT_INACTIVE],
            'event dropped' => [['events' => ['submission.updated']], DeliveryStatus::ERROR_NOT_SUBSCRIBED],
            'form dropped' => [['formIds' => [9]], DeliveryStatus::ERROR_NOT_SUBSCRIBED],
            'store scope dropped' => [['scopeIds' => [2]], DeliveryStatus::ERROR_NOT_SUBSCRIBED],
            'website scope dropped' => [['scopeType' => 'website', 'scopeIds' => [3]], DeliveryStatus::ERROR_NOT_SUBSCRIBED],
        ];
    }

    /** @param array<string, mixed> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('cancelledEndpoints')]
    public function testItCancelsWithoutSendingWhenTheEndpointIsInactiveOrNoLongerSubscribed(array $override, string $reason): void
    {
        $this->view = $this->view($override);

        $this->sender()->send(11, self::NOW);

        $this->assertNothingSent();
        $update = $this->lastUpdate();
        $this->assertSame(DeliveryStatus::CANCELLED, $update['status']);
        $this->assertSame($reason, $update['last_error']);
        $this->assertArrayNotHasKey('attempts', $update);
    }

    public function testItCancelsWhenTheEndpointIsGoneOrBeingPurgedOrTheSubmissionIsMissing(): void
    {
        $this->view = $this->view();
        $this->submissionExists = false;
        $this->sender()->send(11, self::NOW);
        $this->assertNothingSent();
        $this->assertSame(DeliveryStatus::CANCELLED, $this->lastUpdate()['status']);

        $this->deliveryUpdates = [];
        $this->submissionExists = true;
        $this->endpointGone = true;
        $this->sender()->send(11, self::NOW);
        $this->assertNothingSent();
        $this->assertSame(DeliveryStatus::ERROR_ENDPOINT_INACTIVE, $this->lastUpdate()['last_error']);
    }

    public function testItCancelsAWebsiteScopedDeliveryWhoseSubmissionHasNoStore(): void
    {
        $this->view = $this->view(['scopeType' => 'website']);
        $this->submissionStoreId = null;

        $this->sender()->send(11, self::NOW);

        $this->assertNothingSent();
        $this->assertSame(DeliveryStatus::ERROR_NOT_SUBSCRIBED, $this->lastUpdate()['last_error']);
    }

    public function testItDoesNotLoadTheSubmissionWhenTheEndpointIsInactive(): void
    {
        $this->view = $this->view(['active' => false]);
        $this->sender()->send(11, self::NOW);
        $this->assertSame(0, $this->submissionLoads);
        $this->assertSame(DeliveryStatus::ERROR_ENDPOINT_INACTIVE, $this->lastUpdate()['last_error']);

        $this->view = $this->view(['circuit' => gmdate('Y-m-d H:i:s', self::NOW + 900)]);
        $this->sender()->send(11, self::NOW);
        $this->assertSame(0, $this->submissionLoads);
        $this->assertSame(DeliveryStatus::PENDING, $this->lastUpdate()['status']);
    }

    public function testItStillSendsATestDeliveryToAnInactiveEndpoint(): void
    {
        $this->view = $this->view(['active' => false, 'events' => []]);
        $this->delivery['event'] = 'webhook.test';
        $this->delivery['submission_id'] = null;

        $this->sender()->send(11, self::NOW);

        $this->assertCount(1, $this->sent);
        $this->assertSame(DeliveryStatus::SUCCEEDED, $this->lastUpdate()['status']);
    }

    public function testItReschedulesWithoutSendingWhileTheCircuitIsOpen(): void
    {
        $until = gmdate('Y-m-d H:i:s', self::NOW + 900);
        $this->view = $this->view(['circuit' => $until, 'failures' => 5, 'failingSince' => '2026-11-08 09:00:00']);

        $this->sender()->send(11, self::NOW);

        $this->assertNothingSent();
        $this->assertSame(
            ['status' => DeliveryStatus::PENDING, 'next_attempt_at' => $until],
            $this->lastUpdate()
        );
    }

    public function testItFailsAsExpiredWithoutSendingOnceTheRetryWindowEnded(): void
    {
        $this->delivery['created_at'] = gmdate('Y-m-d H:i:s', self::NOW - 4 * 86400);

        $this->sender()->send(11, self::NOW);

        $this->assertNothingSent();
        $update = $this->lastUpdate();
        $this->assertSame(DeliveryStatus::FAILED, $update['status']);
        $this->assertSame(DeliveryStatus::ERROR_EXPIRED, $update['last_error']);
        $this->assertArrayNotHasKey('attempts', $update);
    }

    public function testItMarksADeliveryBlockedAndNotifiesAdminsOnceWhenTheUrlPolicyNowRefusesIt(): void
    {
        $this->urlRefused = true;

        $sender = $this->sender();
        $sender->send(11, self::NOW);

        $this->assertNothingSent();
        $update = $this->lastUpdate();
        $this->assertSame(DeliveryStatus::BLOCKED, $update['status']);
        $this->assertSame(DeliveryStatus::ERROR_BLOCKED, $update['last_error']);
        $this->assertArrayNotHasKey('attempts', $update);
        $this->assertCount(1, $this->notices);
        $this->assertStringContainsString('CRM', $this->notices[0][1]);
        $this->assertStringNotContainsString('token=secret', implode(' ', $this->notices[0]));

        $this->noticeHits = 2;
        $sender->send(11, self::NOW);
        $this->assertCount(1, $this->notices);
    }

    public function testItAlsoNotifiesWhenTheTransportReportsABlockedAddress(): void
    {
        $this->result = new HttpResult(0, [], '', false, HttpResult::ERROR_BLOCKED);

        $this->sender()->send(11, self::NOW);

        $this->assertCount(1, $this->attempts);
        $this->assertSame(DeliveryStatus::BLOCKED, $this->lastUpdate()['status']);
        $this->assertCount(1, $this->notices);
        $this->assertSame([], $this->failures);
    }

    public function testItFailsWithoutSendingWhenTheSecretCannotBeDecrypted(): void
    {
        $this->decrypted = '';

        $this->sender()->send(11, self::NOW);

        $this->assertSame([], $this->sent);
        $this->assertCount(1, $this->attempts);
        $this->assertSame(DeliveryStatus::ERROR_SECRET_UNAVAILABLE, $this->attempts[0][4]);
        $update = $this->lastUpdate();
        $this->assertSame(DeliveryStatus::FAILED, $update['status']);
        $this->assertSame(DeliveryStatus::ERROR_SECRET_UNAVAILABLE, $update['last_error']);
        $this->assertSame(1, $update['attempts']);
        $this->assertSame(['secret_unavailable'], $this->disabled);
        $this->assertSame(['auto_disabled'], $this->audits);
        $this->assertCount(1, $this->notices);
        $this->assertSame([], $this->failures);
    }

    /** @return array<string, array{\Throwable, string}> */
    public static function unsendablePayloads(): array
    {
        return [
            'too large' => [new PayloadTooLargeException('big'), DeliveryStatus::ERROR_PAYLOAD_TOO_LARGE],
            'invalid utf-8' => [new \JsonException('bad'), DeliveryStatus::ERROR_UNSUPPORTED],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsendablePayloads')]
    public function testItFailsFinallyWithoutSendingWhenThePayloadCannotBeBuilt(\Throwable $error, string $class): void
    {
        $this->buildThrows = $error;

        $this->sender()->send(11, self::NOW);

        $this->assertSame([], $this->sent);
        $update = $this->lastUpdate();
        $this->assertSame(DeliveryStatus::FAILED, $update['status']);
        $this->assertSame($class, $update['last_error']);
        $this->assertSame([], $this->failures);
        $this->assertSame([], $this->disabled);
    }

    public function testItDisablesTheEndpointAndNotifiesAdminsOn410(): void
    {
        $this->result = new HttpResult(410);

        $this->sender()->send(11, self::NOW);

        $update = $this->lastUpdate();
        $this->assertSame(DeliveryStatus::FAILED, $update['status']);
        $this->assertSame(DeliveryStatus::ERROR_ENDPOINT_GONE, $update['last_error']);
        $this->assertSame(['gone'], $this->disabled);
        $this->assertSame(['auto_disabled'], $this->audits);
        $this->assertCount(1, $this->notices);
        $this->assertStringContainsString('CRM', $this->notices[0][1]);
        $this->assertStringNotContainsString('token=secret', implode(' ', $this->notices[0]));
    }

    public function testItDisablesTheEndpointAfterThreeDaysOfFailures(): void
    {
        $this->view = $this->view(['failures' => 9, 'failingSince' => gmdate('Y-m-d H:i:s', self::NOW - 3 * 86400)]);
        $this->result = new HttpResult(500);

        $this->sender()->send(11, self::NOW);

        $this->assertSame(DeliveryStatus::PENDING, $this->lastUpdate()['status']);
        $this->assertSame(['failing'], $this->disabled);
        $this->assertSame(['auto_disabled'], $this->audits);
        $this->assertCount(1, $this->notices);
    }

    public function testItStaysSilentWhenAnotherDeliveryAlreadyDisabledTheEndpoint(): void
    {
        $this->result = new HttpResult(410);
        $this->disableWins = false;

        $this->sender()->send(11, self::NOW);

        $this->assertSame([], $this->audits);
        $this->assertSame([], $this->notices);
    }

    public function testItNeverThrows(): void
    {
        $this->result = new HttpResult(410);
        $this->noticeFails = true;
        $this->sender()->send(11, self::NOW);
        $this->assertSame(DeliveryStatus::FAILED, $this->lastUpdate()['status']);

        $this->deliveryUpdates = [];
        $this->noticeFails = false;
        $this->buildThrows = new \RuntimeException('https://crm.example.com/hook?token=secret');
        $this->sender()->send(11, self::NOW);
        $update = $this->lastUpdate();
        $this->assertSame(DeliveryStatus::FAILED, $update['status']);
        $this->assertSame(DeliveryStatus::ERROR_OBSERVER, $update['last_error']);
        $this->assertSame([], $this->failures);

        $this->deliveryUpdates = [];
        $this->deliveryLoadFails = true;
        $this->sender()->send(11, self::NOW);
        $this->assertSame([], $this->deliveryUpdates);
    }

    public function testItLogsOnlyTheClassAndIdsOfAnUnexpectedFailure(): void
    {
        $this->buildThrows = new \RuntimeException('https://crm.example.com/hook?token=secret');

        $this->sender()->send(11, self::NOW);

        $this->assertSame([['RuntimeException', 7, 11]], array_map(
            static fn (array $c): array => [$c['exception_class'], $c['endpoint_id'], $c['delivery_id']],
            $this->logged
        ));
        $this->assertStringNotContainsString('token=secret', json_encode($this->logged, JSON_THROW_ON_ERROR));
    }

    public function testItLetsAnObserverVetoADelivery(): void
    {
        $this->observer = static function (array $data): void {
            $data['veto']->setData('veto', true);
        };

        $this->sender()->send(11, self::NOW);

        $this->assertSame('furan_formbuilder_webhook_send_before', $this->dispatched[0][0]);
        $payload = $this->dispatched[0][1];
        $this->assertEqualsCanonicalizing(['endpoint', 'event', 'delivery_id', 'envelope', 'veto'], array_keys($payload));
        $this->assertSame('submission.created', $payload['event']);
        $this->assertSame(11, $payload['delivery_id']);
        $this->assertNothingSent();
        $update = $this->lastUpdate();
        $this->assertSame(DeliveryStatus::CANCELLED, $update['status']);
        $this->assertSame(DeliveryStatus::ERROR_VETOED, $update['last_error']);
    }

    public function testItDoesNotLetAnObserverChangeTheSignedPayload(): void
    {
        $seen = null;
        $this->observer = static function (array $data) use (&$seen): void {
            $seen = $data['envelope']->getData();
            $data['envelope']->setData('type', 'tampered')->setData('extra', 'x');
        };

        $this->sender()->send(11, self::NOW);

        $this->assertSame(['id' => 'msg_abc', 'type' => 'submission.created'], $seen);
        $this->assertCount(1, $this->sent);
        $this->assertSame(self::BODY, $this->sent[0][2]);
        $this->assertTrue((new SignatureVerifier())->verify(
            $this->secret,
            self::BODY,
            $this->sent[0][1]['webhook-id'],
            $this->sent[0][1]['webhook-timestamp'],
            $this->sent[0][1]['webhook-signature'],
            self::NOW
        ));
    }

    public function testItRetriesPerThePolicyAndLogsOnlyTheClassWhenAnObserverThrows(): void
    {
        $this->observer = static function (): void {
            throw new \RuntimeException('https://crm.example.com/hook?token=secret');
        };

        $this->sender()->send(11, self::NOW);

        $this->assertSame([], $this->sent);
        $update = $this->lastUpdate();
        $this->assertSame(DeliveryStatus::PENDING, $update['status']);
        $this->assertSame(DeliveryStatus::ERROR_OBSERVER, $update['last_error']);
        $this->assertSame(1, $update['attempts']);
        $this->assertSame([], $this->failures);
        $this->assertSame([['RuntimeException', 7, 11]], array_map(
            static fn (array $c): array => [$c['exception_class'], $c['endpoint_id'], $c['delivery_id']],
            $this->logged
        ));
    }

    public function testItNeverPassesTheEndpointSecretToObservers(): void
    {
        $this->sender()->send(11, self::NOW);

        $endpoint = $this->dispatched[0][1]['endpoint'];
        $this->assertSame(EndpointView::class, $endpoint::class);
        $this->assertSame(7, $endpoint->id());
        $dump = print_r($this->dispatched[0][1], true);
        $this->assertStringNotContainsString($this->secret, $dump);
        $this->assertStringNotContainsString('secret_enc', $dump);
        $this->assertStringNotContainsString('whsec_', $dump);
        $this->assertInstanceOf(DataObject::class, $this->dispatched[0][1]['veto']);
    }

    public function testItSendsThroughAReplacedTransportAfterTheUrlPolicyCheck(): void
    {
        $calls = [];
        $this->replacedTransport = new class ($calls) implements WebhookTransportInterface {
            /** @param array<int, string> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function send(SafeUrl $url, RequestPolicy $policy, array $headers, string $body): HttpResult
            {
                $this->calls[] = $url->toString() . '|' . $body;
                return new HttpResult(204, [], '', false, null, 0, 5, null);
            }
        };

        $this->sender()->send(11, self::NOW);

        $this->assertSame(['https://crm.example.com/hook?token=secret|' . self::BODY], $calls);
        $this->assertSame(DeliveryStatus::SUCCEEDED, $this->lastUpdate()['status']);

        $calls = [];
        $this->deliveryUpdates = [];
        $this->urlRefused = true;
        $this->sender()->send(11, self::NOW);

        $this->assertSame([], $calls);
        $this->assertSame(DeliveryStatus::BLOCKED, $this->lastUpdate()['status']);
    }

    private function assertNothingSent(): void
    {
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->attempts);
        $this->assertSame([], $this->failures);
    }
}
