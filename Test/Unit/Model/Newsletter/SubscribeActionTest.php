<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Newsletter;

use Furan\FormBuilder\Api\ActionContext;
use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\FieldType\Newsletter;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\Newsletter\NewsletterBridge;
use Furan\FormBuilder\Model\Newsletter\SubscribeAction;
use Furan\FormBuilder\Test\Unit\Helper\MemoryCounter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SubscribeActionTest extends TestCase
{
    private const SCHEMA = ['pages' => [['items' => [
        ['kind' => 'field', 'type' => 'email', 'name' => 'email', 'settings' => []],
        ['kind' => 'section', 'items' => [
            ['kind' => 'field', 'type' => 'newsletter', 'name' => 'news', 'settings' => []],
        ]],
    ]]]];

    /** @var NewsletterBridge&MockObject */
    private NewsletterBridge $bridge;

    protected function setUp(): void
    {
        $this->bridge = $this->configureBridge($this->createStub(NewsletterBridge::class));
    }

    private ?MemoryCounter $counter = null;

    private function action(?LoggerInterface $logger = null): SubscribeAction
    {
        $pool = new Pool(['newsletter' => new Newsletter($this->bridge), 'email' => new Text()]);

        $this->counter ??= new MemoryCounter();

        return new SubscribeAction($this->bridge, $pool, $this->counter, $logger ?? $this->createStub(LoggerInterface::class));
    }

    private function form(array $settings = ['email_field' => 'email'], array $schema = self::SCHEMA): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getSchema')->willReturn($schema);
        $form->method('getSettings')->willReturn($settings);

        return $form;
    }

    private function submission(?int $customerId, ?int $storeId = 2): SubmissionInterface
    {
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getCustomerId')->willReturn($customerId);
        $submission->method('getStoreId')->willReturn($storeId);

        return $submission;
    }

    private function submit(SubmissionInterface $submission, array $values, ?ActionContext $context = null, ?FormInterface $form = null): void
    {
        $this->action()->execute(
            $form ?? $this->form(),
            $submission,
            $values,
            $context ?? new ActionContext(false, 2, $submission->getCustomerId())
        );
    }

    /**
     * @template T of NewsletterBridge&Stub
     * @param T $bridge
     * @return T
     */
    private function configureBridge(NewsletterBridge&Stub $bridge): NewsletterBridge&Stub
    {
        $bridge->method('isAvailable')->willReturn(true);
        $bridge->method('allowsGuest')->willReturn(true);
        return $bridge;
    }

    public function testItSubscribesTheCustomerAccountWhenALoggedInCustomerTicksTheBox(): void
    {
        $bridge = $this->bridge = $this->configureBridge($this->createMock(NewsletterBridge::class));
        $bridge->expects($this->once())->method('subscribeCustomer')->with(7, 2);
        $bridge->expects($this->never())->method('subscribeGuest');

        $this->submit($this->submission(7), ['news' => 'yes', 'email' => 'x@example.com']);
    }

    public function testItNeverSubscribesATypedEmailForALoggedInCustomer(): void
    {
        $bridge = $this->bridge = $this->configureBridge($this->createMock(NewsletterBridge::class));
        $bridge->expects($this->never())->method('subscribeGuest');
        $bridge->expects($this->once())->method('subscribeCustomer')->with(7, 2);

        $this->submit($this->submission(7), ['news' => 'yes', 'email' => 'victim@example.com']);
    }

    public function testItSubscribesAGuestEmailWhenGuestSubscriptionIsAllowedInThatStore(): void
    {
        $bridge = $this->bridge = $this->configureBridge($this->createMock(NewsletterBridge::class));
        $bridge->expects($this->once())->method('subscribeGuest')->with('guest@example.com', 2);
        $bridge->expects($this->never())->method('subscribeCustomer');

        $this->submit($this->submission(null), ['news' => 'yes', 'email' => 'guest@example.com']);
    }

    public function testItSkipsAGuestWhenGuestSubscriptionIsNotAllowedInThatStore(): void
    {
        $bridge = $this->createMock(NewsletterBridge::class);
        $bridge->method('isAvailable')->willReturn(true);
        $bridge->method('allowsGuest')->with(2)->willReturn(false);
        $bridge->expects($this->never())->method('subscribeGuest');
        $this->bridge = $bridge;

        $this->submit($this->submission(null), ['news' => 'yes', 'email' => 'guest@example.com']);
    }

    public function testItSubscribesAGuestEmailAtMostOncePerDayPerStore(): void
    {
        $bridge = $this->bridge = $this->configureBridge($this->createMock(NewsletterBridge::class));
        $bridge->expects($this->exactly(2))->method('subscribeGuest')
            ->willReturnCallback(static fn (): bool => true);
        $values = ['news' => 'yes', 'email' => 'Guest+a@example.com'];

        $this->submit($this->submission(null, 2), $values);
        $this->submit($this->submission(null, 2), $values);
        $this->submit($this->submission(null, 2), ['news' => 'yes', 'email' => 'guest@example.com']);
        $this->submit($this->submission(null, 3), $values);
    }

    public function testItDoesNothingWhenTheBoxIsNotTicked(): void
    {
        $bridge = $this->bridge = $this->configureBridge($this->createMock(NewsletterBridge::class));
        $bridge->expects($this->never())->method('subscribeGuest');
        $bridge->expects($this->never())->method('subscribeCustomer');

        $this->submit($this->submission(7), ['news' => '', 'email' => 'guest@example.com']);
        $this->submit($this->submission(null), ['email' => 'guest@example.com']);
    }

    public function testItDoesNothingWhenTheNewsletterModuleIsDisabled(): void
    {
        $bridge = $this->createMock(NewsletterBridge::class);
        $bridge->method('isAvailable')->willReturn(false);
        $bridge->expects($this->never())->method('subscribeGuest');
        $bridge->expects($this->never())->method('subscribeCustomer');
        $this->bridge = $bridge;

        $this->submit($this->submission(7), ['news' => 'yes']);
        $this->assertFalse($this->action()->isApplicable($this->form()));
    }

    public function testItDoesNothingWhenNewslettersAreDisabledForTheSubmissionStore(): void
    {
        $bridge = $this->createMock(NewsletterBridge::class);
        $bridge->method('isAvailable')->willReturnCallback(static fn (?int $store = null): bool => $store !== 2);
        $bridge->method('allowsGuest')->willReturn(true);
        $bridge->expects($this->never())->method('subscribeGuest');
        $bridge->expects($this->never())->method('subscribeCustomer');
        $this->bridge = $bridge;

        $this->submit($this->submission(7, 2), ['news' => 'yes']);
        $this->submit($this->submission(null, 2), ['news' => 'yes', 'email' => 'guest@example.com']);
    }

    public function testItNeverSubscribesASubmissionMadeUnderLoginAsCustomer(): void
    {
        $bridge = $this->bridge = $this->configureBridge($this->createMock(NewsletterBridge::class));
        $bridge->expects($this->never())->method('subscribeGuest');
        $bridge->expects($this->never())->method('subscribeCustomer');

        // Processor blanks the newsletter answer under Login as Customer before actions run.
        $this->submit($this->submission(7), ['news' => '', 'email' => 'customer@example.com']);
    }

    public function testItDoesNotSubscribeAQueuedSubmissionMadeUnderLoginAsCustomer(): void
    {
        $bridge = $this->bridge = $this->configureBridge($this->createMock(NewsletterBridge::class));
        $bridge->expects($this->never())->method('subscribeGuest');
        $bridge->expects($this->never())->method('subscribeCustomer');

        // The consumer rebuilds values from the stored payload, which holds the forced blank.
        $this->submit($this->submission(7), ['news' => ''], new ActionContext(false, 0, 7, 1));
    }

    public function testItReadsNewsletterSettingsForTheSubmissionStoreWhenRunFromTheQueue(): void
    {
        $bridge = $this->createMock(NewsletterBridge::class);
        $bridge->method('isAvailable')->willReturn(true);
        $bridge->expects($this->atLeastOnce())->method('allowsGuest')->with(5)->willReturn(true);
        $bridge->expects($this->once())->method('subscribeGuest')->with('guest@example.com', 5);
        $this->bridge = $bridge;

        $this->submit(
            $this->submission(null, 5),
            ['news' => 'yes', 'email' => 'guest@example.com'],
            new ActionContext(false, 0, null, 1)
        );
    }

    public function testItSkipsAGuestWithAnInvalidOrInjectedEmail(): void
    {
        $bridge = $this->bridge = $this->configureBridge($this->createMock(NewsletterBridge::class));
        $bridge->expects($this->never())->method('subscribeGuest');

        $this->submit($this->submission(null), ['news' => 'yes', 'email' => 'not-an-email']);
        $this->submit($this->submission(null), ['news' => 'yes', 'email' => "a@example.com\r\nBcc: b@example.com"]);
    }

    public function testItUsesTheNewsletterFieldsOwnEmailFieldSetting(): void
    {
        $bridge = $this->bridge = $this->configureBridge($this->createMock(NewsletterBridge::class));
        $schema = ['pages' => [['items' => [
            ['kind' => 'field', 'type' => 'email', 'name' => 'work', 'settings' => []],
            ['kind' => 'field', 'type' => 'newsletter', 'name' => 'news', 'settings' => ['email_field' => 'work']],
        ]]]];
        $bridge->expects($this->once())->method('subscribeGuest')->with('w@example.com', 2);

        $this->submit($this->submission(null), ['news' => 'yes', 'work' => 'w@example.com'], null, $this->form([], $schema));
    }

    public function testItNeverRunsOnEditsAndIsApplicableOnlyForFormsWithTheField(): void
    {
        $action = $this->action();

        $this->assertFalse($action->supports(new ActionContext(true, 2)));
        $this->assertTrue($action->supports(new ActionContext(false, 2)));
        $this->assertTrue($action->isApplicable($this->form()));
        $this->assertFalse($action->isApplicable($this->form([], ['pages' => [['items' => []]]])));
    }

    public function testItLogsOnlyTheExceptionClassWhenSubscribingFails(): void
    {
        $this->bridge->method('subscribeGuest')->willThrowException(new \RuntimeException('guest@example.com'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->callback(static fn (string $m): bool => !str_contains($m, 'guest@example.com')),
            $this->callback(static fn (array $c): bool => $c === ['exception_class' => \RuntimeException::class])
        );

        $this->action($logger)->execute(
            $this->form(),
            $this->submission(null),
            ['news' => 'yes', 'email' => 'guest@example.com'],
            new ActionContext(false, 2)
        );
    }
}
