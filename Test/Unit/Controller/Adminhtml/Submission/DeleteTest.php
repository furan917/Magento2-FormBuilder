<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\Submission\Delete;
use Furan\FormBuilder\Controller\Adminhtml\Submission\MassDelete;
use Furan\FormBuilder\Model\Submission\BatchDeleter;
use Furan\FormBuilder\Model\Submission\GridSelection;
use Furan\FormBuilder\Model\Clock;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\NoSuchEntityException;

class DeleteTest extends SubmissionControllerTestCase
{
    public function testItDeletesSubmissionsThroughTheRepositorySoFilesAreRemoved(): void
    {
        $this->expectingRequest()->method('getParam')->with('id')->willReturn('4');
        $submission = $this->createStub(SubmissionInterface::class);
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $repository->method('getById')->with(4)->willReturn($submission);
        $repository->expects($this->once())->method('delete')->with($submission);
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage');
        $this->expectingRedirect()->method('setPath')->with('*/*/')->willReturnSelf();

        $this->assertSame($this->redirect, (new Delete($this->context, $repository))->execute());
    }

    public function testItReportsAnUnknownSubmissionOnDelete(): void
    {
        $this->request->method('getParam')->willReturn('4');
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $repository->method('getById')->willThrowException(new NoSuchEntityException(__('x')));
        $repository->expects($this->never())->method('delete');
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $this->redirect->method('setPath')->willReturnSelf();

        (new Delete($this->context, $repository))->execute();
    }

    /** @var array{mixed, mixed}|null selection the controller handed to GridSelection */
    private ?array $passed = null;

    /** @param array<int, int[]> $batches */
    private function selection(array $batches, ?array &$where = null): GridSelection
    {
        $selection = $this->createStub(GridSelection::class);
        $selection->method('batches')->willReturnCallback(function (int $size, mixed $selected, mixed $excluded, array $extra = []) use ($batches, &$where) {
            $where = $extra;
            $this->passed = [$selected, $excluded];
            yield from $batches;
        });
        return $selection;
    }

    public function testItMassDeletesTheSelectedIdsInBatches(): void
    {
        $batches = [];
        $deleter = $this->createStub(BatchDeleter::class);
        $deleter->method('deleteBatch')->willReturnCallback(function (array $ids) use (&$batches): int {
            $batches[] = $ids;
            return count($ids);
        });
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage')
            ->with(__('A total of %1 submission(s) have been deleted.', 501));
        $this->expectingRedirect()->method('setPath')->with('*/*/')->willReturnSelf();

        (new MassDelete($this->context, $this->selection([range(1, 500), [501]]), $deleter, new Clock()))->execute();

        $this->assertSame([range(1, 500), [501]], $batches);
    }

    public function testItPassesTheMassActionSelectionToTheGridSelection(): void
    {
        $this->request->method('getParam')->willReturnMap([['selected', null, ['4', '5']], ['excluded', null, ['9']]]);
        $this->redirect->method('setPath')->willReturnSelf();

        (new MassDelete($this->context, $this->selection([]), $this->createStub(BatchDeleter::class), new Clock()))->execute();

        $this->assertSame([['4', '5'], ['9']], $this->passed);
    }

    public function testItReportsBatchesDeletedBeforeAFailure(): void
    {
        $deleter = $this->createStub(BatchDeleter::class);
        $deleter->method('deleteBatch')->willReturnOnConsecutiveCalls(
            500,
            $this->throwException(new CouldNotDeleteException(__('Could not delete the submission.')))
        );
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage')->with('Could not delete the submission.');
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage')
            ->with(__('A total of %1 submission(s) have been deleted.', 500));
        $this->expectingRedirect()->method('setPath')->with('*/*/')->willReturnSelf();

        (new MassDelete($this->context, $this->selection([range(1, 500), range(501, 600)]), $deleter, new Clock()))->execute();
    }

    public function testItStopsAMassDeleteAtTheRequestTimeBudgetAndAsksToRunItAgain(): void
    {
        $now = 0.0;
        $deleter = $this->createMock(BatchDeleter::class);
        $deleter->expects($this->exactly(2))->method('deleteBatch')->willReturnCallback(function (array $ids) use (&$now): int {
            $now += MassDelete::BUDGET_SECONDS / 2;
            return count($ids);
        });
        $this->expectingMessages()->expects($this->once())->method('addNoticeMessage');
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage')
            ->with(__('A total of %1 submission(s) have been deleted.', 2));
        $this->redirect->method('setPath')->willReturnSelf();

        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturnCallback(static function () use (&$now): float {
            return $now;
        });

        (new MassDelete($this->context, $this->selection([[1], [2], [3], [4]]), $deleter, $clock))->execute();
    }
}
