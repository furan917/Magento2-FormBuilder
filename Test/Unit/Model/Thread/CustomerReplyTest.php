<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Thread;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Customer\Impersonation;
use Furan\FormBuilder\Model\Thread\CustomerReply;
use Furan\FormBuilder\Model\Thread\CustomerReplyNotifier;
use Furan\FormBuilder\Model\Thread\MessageSanitizer;
use Furan\FormBuilder\Model\Thread\Messages;
use Furan\FormBuilder\Model\Thread\ReplyOutcome;
use Furan\FormBuilder\Model\Thread\ReplyPolicy;
use Furan\FormBuilder\Model\Thread\ThreadLimits;
use Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CustomerReplyTest extends TestCase
{
    use AssertedDoubles;

    private bool $impersonating = false;
    private bool $repliesOpen = true;
    private bool $full = false;
    private bool $limitAllows = true;
    private int $limiterCalls = 0;
    private int $status = SubmissionInterface::STATUS_NEW;
    private Messages&Stub $messages;
    private CustomerReplyNotifier&Stub $notifier;
    private LoggerInterface&Stub $logger;
    private SubmissionInterface&Stub $submission;
    private FormInterface&Stub $form;
    private CustomerReply $service;

    private function build(): void
    {
        $this->messages = $this->double('messages', Messages::class);
        $this->notifier = $this->double('notifier', CustomerReplyNotifier::class);
        $this->logger = $this->double('logger', LoggerInterface::class);
        $this->submission = $this->createStub(SubmissionInterface::class);
        $this->submission->method('getId')->willReturn(9);
        $this->submission->method('getStatus')->willReturnCallback(fn (): int => $this->status);
        $this->form = $this->createStub(FormInterface::class);
        $this->form->method('getSettings')->willReturn(['customer_replies' => 'on']);
        $impersonation = $this->createStub(Impersonation::class);
        $impersonation->method('isActive')->willReturnCallback(fn (): bool => $this->impersonating);
        $policy = $this->createStub(ReplyPolicy::class);
        $policy->method('customerRepliesOpen')->willReturnCallback(fn (): bool => $this->repliesOpen);
        $policy->method('guestRepliesOpen')->willReturnCallback(fn (): bool => $this->repliesOpen);
        $policy->method('isFull')->willReturnCallback(fn (): bool => $this->full);
        $limits = $this->createStub(ThreadLimits::class);
        $limits->method('allowCustomerReply')->willReturnCallback(function (): bool {
            $this->limiterCalls++;
            return $this->limitAllows;
        });
        $limits->method('allowGuestReply')->willReturnCallback(function (): bool {
            $this->limiterCalls++;
            return $this->limitAllows;
        });

        $this->service = new CustomerReply(
            $this->messages,
            $this->notifier,
            $policy,
            $limits,
            $impersonation,
            new MessageSanitizer(),
            $this->logger
        );
    }

    private function post(string $author, string $body = 'Thanks!'): ReplyOutcome
    {
        return $author === 'guest'
            ? $this->service->postAsGuest($this->submission, $this->form, $body, 'guest@x.test', 4)
            : $this->service->postAsCustomer($this->submission, $this->form, $body, 'cust@x.test', 4, 5);
    }

    /** @return array<string, array{string}> */
    public static function authors(): array
    {
        return ['customer' => ['customer'], 'guest' => ['guest']];
    }

    #[DataProvider('authors')]
    public function testItAppliesTheSameChecksInTheSameOrderForCustomersAndGuests(string $author): void
    {
        $this->build();
        $this->impersonating = true;
        $this->status = SubmissionInterface::STATUS_CLOSED;
        $this->repliesOpen = false;
        $this->full = true;
        $this->limitAllows = false;
        $body = str_repeat('a', 5001);

        $steps = [
            [403, static function (self $t): void {
                $t->impersonating = false;
            }],
            [422, static function (self $t) use (&$body): void {
                $body = 'Thanks!';
            }],
            [409, static function (self $t): void {
                $t->status = SubmissionInterface::STATUS_NEW;
            }],
            [409, static function (self $t): void {
                $t->repliesOpen = true;
            }],
            [409, static function (self $t): void {
                $t->full = false;
            }],
            [429, static function (self $t): void {
            }],
        ];
        $messages = [];
        foreach ($steps as $index => [$code, $clear]) {
            $outcome = $this->post($author, $body);
            $this->assertFalse($outcome->isSent(), (string)$index);
            $this->assertSame($code, $outcome->code, (string)$index);
            $messages[] = $outcome->message;
            $clear($this);
        }
        $this->assertCount(6, array_unique($messages));
        $this->assertSame(1, $this->limiterCalls, 'only the last refusal reached the rate limit');
    }

    #[DataProvider('authors')]
    public function testItCountsAReplyAgainstTheHourlyLimitOnlyAfterEveryOtherCheckPasses(string $author): void
    {
        $this->build();
        foreach ([
            static fn (self $t) => $t->impersonating = true,
            static fn (self $t) => $t->status = SubmissionInterface::STATUS_CLOSED,
            static fn (self $t) => $t->repliesOpen = false,
            static fn (self $t) => $t->full = true,
        ] as $break) {
            $this->setUpFresh();
            $break($this);
            $this->assertFalse($this->post($author)->isSent());
            $this->assertSame(0, $this->limiterCalls);
        }
        $this->setUpFresh();
        $this->assertFalse($this->post($author, '')->isSent());
        $this->assertSame(0, $this->limiterCalls);

        $this->setUpFresh();
        $this->assertTrue($this->post($author)->isSent());
        $this->assertSame(1, $this->limiterCalls);
    }

    private function setUpFresh(): void
    {
        $this->impersonating = false;
        $this->repliesOpen = true;
        $this->full = false;
        $this->limitAllows = true;
        $this->limiterCalls = 0;
        $this->status = SubmissionInterface::STATUS_NEW;
        $this->build();
    }

    #[DataProvider('authors')]
    public function testItReopensAnAnsweredSubmissionAndNotifiesTheMerchantAfterACustomerReply(string $author): void
    {
        $this->asserted = ['messages', 'notifier'];
        $this->build();
        $this->status = SubmissionInterface::STATUS_ANSWERED;
        $this->messages->expects($this->once())->method('addCustomer')->with(9, 'Thanks!', $author)->willReturn(31);
        $this->messages->expects($this->once())->method('reopenIfAnswered')->with(9);
        $email = $author === 'guest' ? 'guest@x.test' : 'cust@x.test';
        $this->notifier->expects($this->once())->method('notify')
            ->with($this->form, $this->submission, 31, $email, 'Thanks!');

        $this->assertTrue($this->post($author)->isSent());
    }

    public function testItDoesNotReopenASubmissionThatIsNotAnswered(): void
    {
        $this->asserted = ['messages'];
        $this->build();
        $this->messages->expects($this->once())->method('addCustomer');
        $this->messages->expects($this->never())->method('reopenIfAnswered');

        $this->assertTrue($this->post('customer')->isSent());
    }

    #[DataProvider('authors')]
    public function testItLogsAFailedNotificationWithoutTheExceptionMessage(string $author): void
    {
        $this->asserted = ['logger'];
        $this->build();
        $this->notifier->method('notify')->willThrowException(new \RuntimeException('to jane@example.com failed'));
        $this->logger->expects($this->once())->method('error')->with($this->callback(
            static fn (string $m): bool => str_contains($m, \RuntimeException::class) && !str_contains($m, 'jane@example.com')
        ));

        $this->assertTrue($this->post($author)->isSent());
    }

    public function testItStoresTheSanitisedBody(): void
    {
        $this->asserted = ['messages'];
        $this->build();
        $this->messages->expects($this->once())->method('addCustomer')->with(9, "Hi there\n\nok", 'customer');

        $this->assertTrue($this->post('customer', "Hi\u{202E} there  \r\n\r\n\r\nok\u{200B}")->isSent());
    }

    public function testItMeasuresTheLengthAfterSanitising(): void
    {
        $this->build();

        $this->assertTrue($this->post('customer', str_repeat("a\u{200B}", 5000))->isSent());
        $this->assertSame(422, $this->post('customer', "\u{200B}\u{00A0}")->code);
    }
}
