<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Action;

use Furan\FormBuilder\Api\ActionContext;
use Furan\FormBuilder\Api\ActionRetrySchedulerInterface;
use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\DeferrableActionInterface;
use Furan\FormBuilder\Api\SubmissionActionInterface;
use Furan\FormBuilder\Model\Action\ActionExecutor;
use Furan\FormBuilder\Model\Action\ActionQueue;
use Furan\FormBuilder\Model\Action\ActionRunner;
use Furan\FormBuilder\Model\Action\SubmissionActionPool;
use Furan\FormBuilder\Model\Submission\SubmissionStore;
use Furan\FormBuilder\Test\Unit\Model\Submission\SubmissionStub;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ActionRunnerTest extends TestCase
{
    private function runner(array $actions, ?ActionQueue $queue = null, ?LoggerInterface $logger = null): ActionRunner
    {
        return new ActionRunner(
            new SubmissionActionPool($actions),
            $queue ?? $this->createStub(ActionQueue::class),
            new ActionExecutor(
                $this->createStub(ActionRetrySchedulerInterface::class),
                $logger ?? $this->createStub(LoggerInterface::class)
            ),
            new SubmissionStore($this->createStub(StoreManagerInterface::class))
        );
    }

    private function submission(int $id = 41): SubmissionStub
    {
        $submission = (new SubmissionStub())->setStoreId(2)->setCustomerId(5);
        $submission->id = $id;

        return $submission;
    }

    private function action(string $interface, bool $supports = true): SubmissionActionInterface&MockObject
    {
        $action = $this->createMock($interface);
        $action->method('supports')->willReturn($supports);
        if ($action instanceof DeferrableActionInterface) {
            $action->method('isApplicable')->willReturn(true);
        }

        return $action;
    }

    public function testItRunsEveryActionThatSupportsTheContextWithTheStoreCustomerAndEditFlag(): void
    {
        $run = $this->action(SubmissionActionInterface::class);
        $run->expects($this->once())->method('execute')->with(
            $this->anything(),
            $this->anything(),
            ['name' => 'Ann'],
            $this->callback(static fn (ActionContext $c): bool => $c->storeId === 2 && $c->customerId === 5
                && $c->isEdit === true && $c->attempt === 1)
        );
        $skipped = $this->action(SubmissionActionInterface::class, false);
        $skipped->expects($this->never())->method('execute');

        $this->runner(['run' => $run, 'skipped' => $skipped])
            ->run($this->createStub(FormInterface::class), $this->submission(), ['name' => 'Ann'], true);
    }

    public function testItRunsActionsAsTheFormsStoreViewWhenTheSubmissionsStoreViewWasDeleted(): void
    {
        $run = $this->action(SubmissionActionInterface::class);
        $run->expects($this->once())->method('execute')->with(
            $this->anything(),
            $this->anything(),
            [],
            $this->callback(static fn (ActionContext $c): bool => $c->storeId === 3)
        );
        $form = $this->createStub(FormInterface::class);
        $form->method('getStoreIds')->willReturn([3]);

        $this->runner(['run' => $run])->run($form, $this->submission()->setStoreId(null), []);
    }

    public function testItQueuesDeferrableActionsOfStoredSubmissionsAndRunsTheRestInline(): void
    {
        $queue = $this->createMock(ActionQueue::class);
        $queue->expects($this->exactly(2))->method('publish')->willReturnCallback(
            static fn (int $id, string $key, int $attempt, bool $edit): bool => $key === 'queued'
        );
        $queued = $this->action(DeferrableActionInterface::class);
        $queued->expects($this->never())->method('execute');
        $declined = $this->action(DeferrableActionInterface::class);
        $declined->expects($this->once())->method('execute');

        $this->runner(['queued' => $queued, 'declined' => $declined], $queue)
            ->run($this->createStub(FormInterface::class), $this->submission(), []);
    }

    public function testItNeverQueuesASubmissionThatIsNotStored(): void
    {
        $queue = $this->createMock(ActionQueue::class);
        $queue->expects($this->never())->method('publish');
        $mail = $this->action(DeferrableActionInterface::class);
        $mail->expects($this->once())->method('execute');

        $this->runner(['mail' => $mail], $queue)
            ->run($this->createStub(FormInterface::class), $this->submission(0), []);
    }
}
