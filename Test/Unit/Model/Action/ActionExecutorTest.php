<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Action;

use Furan\FormBuilder\Api\ActionContext;
use Furan\FormBuilder\Api\ActionRetrySchedulerInterface;
use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\RetryableActionInterface;
use Furan\FormBuilder\Api\SubmissionActionInterface;
use Furan\FormBuilder\Model\Action\ActionExecutor;
use Furan\FormBuilder\Test\Unit\Model\Submission\SubmissionStub;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ActionExecutorTest extends TestCase
{
    private ActionRetrySchedulerInterface&Stub $scheduler;
    private LoggerInterface&Stub $logger;

    protected function setUp(): void
    {
        $this->scheduler = $this->createStub(ActionRetrySchedulerInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    private function runAction(
        SubmissionActionInterface $action,
        int $attempt = 1,
        int $id = 41,
        bool $isEdit = false
    ): void
    {
        $submission = (new SubmissionStub())->setStoreId(2)->setCustomerId(8);
        $submission->id = $id;
        (new ActionExecutor($this->scheduler, $this->logger))->run(
            'crm',
            $action,
            $this->createStub(FormInterface::class),
            $submission,
            ['a' => 'b'],
            new ActionContext($isEdit, 2, 8, $attempt)
        );
    }

    private function retryable(int $max, bool $fails = true, bool $retries = true): SubmissionActionInterface&RetryableActionInterface&Stub
    {
        $action = $this->createStubForIntersectionOfInterfaces([SubmissionActionInterface::class, RetryableActionInterface::class]);
        $action->method('maxAttempts')->willReturn($max);
        $action->method('isRetryable')->willReturn($retries);
        if ($fails) {
            $action->method('execute')->willThrowException(new \RuntimeException('crm down'));
        }
        return $action;
    }

    public function testItPassesAnActionContextWithStoreCustomerEditFlagAndAttempt(): void
    {
        $action = $this->createMock(SubmissionActionInterface::class);
        $action->expects($this->once())->method('execute')->with(
            $this->anything(),
            $this->anything(),
            ['a' => 'b'],
            $this->callback(static fn (ActionContext $c): bool => $c->storeId === 2
                && $c->customerId === 8 && $c->isEdit === false && $c->attempt === 3)
        );

        $this->runAction($action, 3);
    }

    public function testItHandsAFailedRetryableActionToTheRetrySchedulerUntilItsMaxAttempts(): void
    {
        $logger = $this->logger = $this->createMock(LoggerInterface::class);
        $scheduler = $this->scheduler = $this->createMock(ActionRetrySchedulerInterface::class);
        $scheduler->expects($this->once())->method('schedule')->with(41, 'crm', 2, 'RuntimeException', false);
        $logger->expects($this->exactly(2))->method('error');

        $this->runAction($this->retryable(3), 2);
        $this->runAction($this->retryable(3), 3);
    }

    public function testItKeepsTheEditFlagOnTheScheduledRetry(): void
    {
        $scheduler = $this->scheduler = $this->createMock(ActionRetrySchedulerInterface::class);
        $scheduler->expects($this->once())->method('schedule')->with(41, 'crm', 1, 'RuntimeException', true);

        $this->runAction($this->retryable(3), 1, 41, true);
    }

    public function testItClampsMaxAttemptsToBetweenOneAndFive(): void
    {
        $scheduler = $this->scheduler = $this->createMock(ActionRetrySchedulerInterface::class);
        $scheduler->expects($this->once())->method('schedule')->with(41, 'crm', 4, 'RuntimeException', false);

        $this->runAction($this->retryable(0), 1);
        $this->runAction($this->retryable(99), 4);
        $this->runAction($this->retryable(99), 5);
    }

    public function testItNeverRetriesAnActionThatIsNotRetryable(): void
    {
        $logger = $this->logger = $this->createMock(LoggerInterface::class);
        $scheduler = $this->scheduler = $this->createMock(ActionRetrySchedulerInterface::class);
        $action = $this->createStub(SubmissionActionInterface::class);
        $action->method('execute')->willThrowException(new \RuntimeException('smtp down'));
        $scheduler->expects($this->never())->method('schedule');
        $logger->expects($this->once())->method('error');

        $this->runAction($action);
    }

    public function testItDoesNotRetryAFailureTheActionDoesNotRetry(): void
    {
        $scheduler = $this->scheduler = $this->createMock(ActionRetrySchedulerInterface::class);
        $scheduler->expects($this->never())->method('schedule');

        $this->runAction($this->retryable(3, true, false));
    }

    public function testItGivesUpAndLogsAfterTheActionsMaxAttempts(): void
    {
        $logger = $this->logger = $this->createMock(LoggerInterface::class);
        $scheduler = $this->scheduler = $this->createMock(ActionRetrySchedulerInterface::class);
        $scheduler->expects($this->never())->method('schedule');
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('gave up'), $this->anything());

        $this->runAction($this->retryable(2), 2);
    }

    public function testItDoesNotScheduleARetryWhenThereIsNoStoredSubmission(): void
    {
        $scheduler = $this->scheduler = $this->createMock(ActionRetrySchedulerInterface::class);
        $scheduler->expects($this->never())->method('schedule');

        $this->runAction($this->retryable(3), 1, 0);
    }
}
