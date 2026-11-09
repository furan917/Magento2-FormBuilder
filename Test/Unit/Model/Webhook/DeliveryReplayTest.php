<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Http\UnsafeUrlException;
use Furan\FormBuilder\Model\Spam\RateLimiter;
use Furan\FormBuilder\Model\Webhook\DeliveryLog;
use Furan\FormBuilder\Model\Webhook\DeliveryReplay;
use Furan\FormBuilder\Model\Webhook\DeliveryStore;
use Furan\FormBuilder\Model\Webhook\EndpointRepository;
use Furan\FormBuilder\Model\Webhook\EndpointView;
use Furan\FormBuilder\Model\Webhook\Outbox;
use Furan\FormBuilder\Model\Webhook\UrlPolicy;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class DeliveryReplayTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private DeliveryStore&Stub $store;
    private EndpointRepository&Stub $repository;
    private Outbox&Stub $outbox;
    private UrlPolicy&Stub $policy;
    private RateLimiter&Stub $limiter;
    private DeliveryLog&Stub $log;

    /** @var array<string, mixed>|null */
    private ?array $original = ['delivery_id' => 40, 'endpoint_id' => 7, 'submission_id' => 12, 'event' => 'submission.created'];

    private function replay(bool $enabled = true, bool $active = true, bool $allowed = true, bool $submission = true, bool $personal = false): DeliveryReplay
    {
        $this->store = $this->createStub(DeliveryStore::class);
        $this->store->method('load')->willReturnCallback(fn (): ?array => $this->original);
        $view = $this->createStub(EndpointView::class);
        $view->method('isActive')->willReturn($active);
        $view->method('includePersonal')->willReturn($personal);
        $view->method('url')->willReturn('https://crm.example.com/in');
        $this->repository = $this->createStub(EndpointRepository::class);
        $this->repository->method('getView')->willReturn($view);
        $this->outbox = $this->double('outbox', Outbox::class);
        $this->policy = $this->createStub(UrlPolicy::class);
        $this->policy->method('isEnabled')->willReturn($enabled);
        $this->limiter = $this->double('limiter', RateLimiter::class);
        $this->limiter->method('hit')->willReturn($allowed);
        $this->log = $this->createStub(DeliveryLog::class);
        $this->log->method('submissionExists')->willReturn($submission);

        return new DeliveryReplay($this->store, $this->repository, $this->outbox, $this->policy, $this->limiter, $this->log);
    }

    public function testItRedeliversAsANewRowWithTheSameWebhookId(): void
    {
        $this->asserted = ['outbox'];
        $replay = $this->replay();
        $this->outbox->expects($this->once())->method('recordReplay')->with(40, 9, false)->willReturn(41);
        $this->outbox->expects($this->once())->method('wake')->with([41]);

        $this->assertSame(41, $replay->replay(40, 9, false, true));
    }

    public function testItResendsAsANewRowWithANewWebhookId(): void
    {
        $this->asserted = ['outbox'];
        $replay = $this->replay();
        $this->outbox->expects($this->once())->method('recordReplay')->with(40, 9, true)->willReturn(42);
        $this->outbox->expects($this->once())->method('wake')->with([42]);

        $this->assertSame(42, $replay->replay(40, 9, true, true));
    }

    public function testItRecordsTheAdminWhoReplayedADeliveryAndLimitsThemPerAdmin(): void
    {
        $this->asserted = ['outbox', 'limiter'];
        $replay = $this->replay();
        $this->limiter->expects($this->once())->method('hit')->with('webhook_replay_9', 30, 600)->willReturn(true);
        $this->outbox->expects($this->once())->method('recordReplay')->with($this->anything(), 9, $this->anything())->willReturn(1);

        $replay->replay(40, 9, false, true);
    }

    /** @return array<string, array{bool, bool, bool, bool}> */
    public static function refusals(): array
    {
        return [
            'kill switch off' => [false, true, true, true],
            'endpoint inactive' => [true, false, true, true],
            'rate limit' => [true, true, false, true],
            'submission gone' => [true, true, true, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testItRefusesReplayWhenTheEndpointOrSubmissionOrSwitchDoNotAllowIt(bool $enabled, bool $active, bool $allowed, bool $submission): void
    {
        $this->asserted = ['outbox'];
        $replay = $this->replay($enabled, $active, $allowed, $submission);
        $this->outbox->expects($this->never())->method('recordReplay');
        $this->outbox->expects($this->never())->method('wake');

        $this->expectException(LocalizedException::class);
        $replay->replay(40, 9, false, true);
    }

    public function testItRefusesReplayWhenTheUrlNowFailsThePolicy(): void
    {
        $this->asserted = ['outbox'];
        $replay = $this->replay();
        $this->policy->method('validateForSend')->willThrowException(new UnsafeUrlException('address_not_public'));
        $this->outbox->expects($this->never())->method('recordReplay');

        $this->expectException(LocalizedException::class);
        $replay->replay(40, 9, false, true);
    }

    public function testItRefusesReplayOfAnUnknownDeliveryOrAPurgedEndpoint(): void
    {
        $replay = $this->replay();
        $this->original = null;
        try {
            $replay->replay(404, 9, false, true);
            $this->fail('unknown delivery');
        } catch (NoSuchEntityException) {
            $this->addToAssertionCount(1);
        }
        $this->original = ['delivery_id' => 40, 'endpoint_id' => 7, 'submission_id' => 12, 'event' => 'x'];
        $replay = $this->replay();
        $this->repository->method('getView')->willThrowException(new NoSuchEntityException(__('gone')));
        $this->expectException(NoSuchEntityException::class);
        $replay->replay(40, 9, false, true);
    }

    public function testItRefusesToReplayATestEvent(): void
    {
        $this->asserted = ['outbox'];
        $this->original = ['delivery_id' => 40, 'endpoint_id' => 7, 'submission_id' => null, 'event' => Outbox::TEST_EVENT];
        $replay = $this->replay(true, true, true, false);
        $this->outbox->expects($this->never())->method('recordReplay');

        $this->expectException(LocalizedException::class);
        $replay->replay(40, 9, false, true);
    }

    public function testItRefusesToReplayToAPersonalEndpointWithoutThePersonalPermission(): void
    {
        $this->asserted = ['outbox'];
        $replay = $this->replay(true, true, true, true, true);
        $this->outbox->expects($this->never())->method('recordReplay');

        $this->expectException(LocalizedException::class);
        $replay->replay(40, 9, false, false);
    }

    public function testItReplaysToAPersonalEndpointWithThePersonalPermission(): void
    {
        $this->asserted = ['outbox'];
        $replay = $this->replay(true, true, true, true, true);
        $this->outbox->expects($this->once())->method('recordReplay')->willReturn(44);

        $this->assertSame(44, $replay->replay(40, 9, false, true));
    }
}
