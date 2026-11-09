<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Spam\RateLimiter;
use Furan\FormBuilder\Model\Webhook\Audit;
use Furan\FormBuilder\Model\Webhook\EndpointActions;
use Furan\FormBuilder\Model\Webhook\EndpointView;
use Furan\FormBuilder\Model\Webhook\EndpointRepository;
use Furan\FormBuilder\Model\Webhook\EndpointSecret;
use Furan\FormBuilder\Model\Webhook\Outbox;
use Furan\FormBuilder\Model\Webhook\UrlPolicy;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class EndpointActionsTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private EndpointRepository&Stub $repository;
    private Outbox&Stub $outbox;
    private UrlPolicy&Stub $policy;
    private RateLimiter&Stub $limiter;
    private Audit&Stub $audit;

    private function actions(bool $enabled = true, bool $allowed = true): EndpointActions
    {
        $this->repository = $this->double('repository', EndpointRepository::class);
        $this->outbox = $this->double('outbox', Outbox::class);
        $this->policy = $this->createStub(UrlPolicy::class);
        $this->policy->method('isEnabled')->willReturn($enabled);
        $this->limiter = $this->double('limiter', RateLimiter::class);
        $this->limiter->method('hit')->willReturn($allowed);

        $this->audit = $this->double('audit', Audit::class);

        return new EndpointActions($this->repository, $this->outbox, $this->policy, $this->limiter, $this->audit);
    }

    public function testItRotatesTheSecretAndReturnsTheNewOneOnce(): void
    {
        $this->asserted = ['repository'];
        $actions = $this->actions();
        $secret = new EndpointSecret(5, 'whsec_new');
        $this->repository->expects($this->once())->method('rotate')->with(5, 24, 9)->willReturn($secret);

        $this->assertSame($secret, $actions->rotate(5, 24, 9));
    }

    public function testItKeepsTheOldSecretOnlyForTheChosenWindowUpToSeventyTwoHours(): void
    {
        $actions = $this->actions();
        $seen = [];
        $this->repository->method('rotate')->willReturnCallback(
            function (int $id, int $hours) use (&$seen): EndpointSecret {
                $seen[] = $hours;
                return new EndpointSecret($id, 'x');
            }
        );

        $actions->rotate(5, -4, 9);
        $actions->rotate(5, 1000, 9);
        $actions->rotate(5, 12, 9);

        $this->assertSame([0, 72, 12], $seen);
    }

    public function testItRefusesToRotateAnEndpointBeingPurged(): void
    {
        $actions = $this->actions();
        $this->repository->method('rotate')->willThrowException(new NoSuchEntityException(__('gone')));

        $this->expectException(NoSuchEntityException::class);
        $actions->rotate(5, 0, 9);
    }

    public function testItQueuesATestDeliveryAndWakesTheWorker(): void
    {
        $this->asserted = ['repository', 'outbox'];
        $actions = $this->actions();
        $this->repository->expects($this->once())->method('getView')->with(5);
        $this->outbox->expects($this->once())->method('recordTest')->with(5, 9)->willReturn(77);
        $this->outbox->expects($this->once())->method('wake')->with([77]);

        $this->assertSame(77, $actions->sendTest(5, 9));
    }

    public function testItLimitsHowOftenAnAdminCanSendTestEvents(): void
    {
        $this->asserted = ['outbox'];
        $actions = $this->actions(true, false);
        $this->outbox->expects($this->never())->method('recordTest');
        $this->outbox->expects($this->never())->method('wake');

        $this->expectException(LocalizedException::class);
        $actions->sendTest(5, 9);
    }

    public function testItRateLimitsPerAdminAndEndpointTenPerTenMinutes(): void
    {
        $this->asserted = ['limiter'];
        $actions = $this->actions();
        $this->limiter->expects($this->once())->method('hit')->with('webhook_test_9_5', 10, 600)->willReturn(true);
        $actions = new EndpointActions($this->repository, $this->outbox, $this->policy, $this->limiter, $this->audit);
        $this->outbox->method('recordTest')->willReturn(1);

        $actions->sendTest(5, 9);
    }

    public function testItRefusesTestEventsWhileWebhooksAreDisabledInEnvPhp(): void
    {
        $this->asserted = ['outbox'];
        $actions = $this->actions(false);
        $this->outbox->expects($this->never())->method('recordTest');

        $this->expectException(LocalizedException::class);
        $actions->sendTest(5, 9);
    }

    public function testItRefusesTestEventsForAnEndpointBeingPurged(): void
    {
        $this->asserted = ['outbox'];
        $actions = $this->actions();
        $this->repository->method('getView')->willThrowException(new NoSuchEntityException(__('gone')));
        $this->outbox->expects($this->never())->method('recordTest');

        $this->expectException(NoSuchEntityException::class);
        $actions->sendTest(5, 9);
    }

    public function testItAuditsATestSend(): void
    {
        $this->asserted = ['audit'];
        $actions = $this->actions();
        $view = $this->createStub(EndpointView::class);
        $view->method('url')->willReturn('https://crm.example.com/in?token=abc');
        $this->repository->method('getView')->willReturn($view);
        $this->outbox->method('recordTest')->willReturn(77);
        $this->audit->expects($this->once())->method('record')->with(5, 9, 'test_sent', 'crm.example.com');

        $actions->sendTest(5, 9);
    }

    public function testItDoesNotAuditATestSendTheRateLimiterRefused(): void
    {
        $this->asserted = ['audit'];
        $actions = $this->actions(true, false);
        $this->audit->expects($this->never())->method('record');

        try {
            $actions->sendTest(5, 9);
        } catch (LocalizedException) {
            $this->addToAssertionCount(1);
        }
    }
}
