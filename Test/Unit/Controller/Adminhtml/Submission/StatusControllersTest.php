<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Controller\Adminhtml\Submission\MassStatus;
use Furan\FormBuilder\Controller\Adminhtml\Submission\SaveStatus;
use Furan\FormBuilder\Model\Source\SubmissionStatus;
use Furan\FormBuilder\Model\Submission\GridSelection;
use Furan\FormBuilder\Model\Submission\StatusWriter;
use Furan\FormBuilder\Model\Clock;
use PHPUnit\Framework\MockObject\Stub;

class StatusControllersTest extends SubmissionControllerTestCase
{
    private StatusWriter&Stub $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redirect->method('setPath')->willReturnSelf();
        $this->writer = $this->createStub(StatusWriter::class);
    }

    private array $selectionWhere = [];
    /** @var array{mixed, mixed}|null */
    private ?array $passed = null;

    /** @return list<array{string, null, mixed}> */
    private function massParams(string $status): array
    {
        return [['status', null, $status], ['selected', null, ['4']], ['excluded', null, 'false']];
    }

    private function massStatus(array $ids): MassStatus
    {
        $selection = $this->createStub(GridSelection::class);
        $selection->method('batches')->willReturnCallback(function (int $size, mixed $selected, mixed $excluded, array $where = []) use ($ids) {
            $this->selectionWhere = $where;
            $this->passed = [$selected, $excluded];
            yield from array_chunk($ids, $size);
        });
        return new MassStatus($this->context, $selection, new SubmissionStatus(), $this->writer, new Clock());
    }

    private function saveStatus(): SaveStatus
    {
        return new SaveStatus($this->context, new SubmissionStatus(), $this->writer);
    }

    public function testItChangesTheStatusOfSelectedSubmissionsThroughTheStatusWriter(): void
    {
        $writer = $this->writer = $this->createMock(StatusWriter::class);
        $this->request->method('getParam')->willReturnMap($this->massParams('2'));
        $writer->expects($this->once())->method('change')->with([4, 5], 2, 'admin')->willReturn(2);
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage');

        $this->assertSame($this->redirect, $this->massStatus([4, 5])->execute());
    }

    public function testItRejectsAnUnknownMassStatusValue(): void
    {
        $writer = $this->writer = $this->createMock(StatusWriter::class);
        $this->request->method('getParam')->willReturnMap($this->massParams('9'));
        $writer->expects($this->never())->method('change');
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');

        $this->massStatus([4])->execute();
    }

    public function testItUpdatesStatusesInBoundedIdBatchesWithoutLoadingSubmissionModels(): void
    {
        $this->request->method('getParam')->willReturnMap($this->massParams('3'));
        $sizes = [];
        $this->writer->method('change')->willReturnCallback(
            static function (array $ids) use (&$sizes): int {
                $sizes[] = count($ids);
                return count($ids);
            }
        );

        $this->massStatus(range(1, 1200))->execute();

        $this->assertSame([500, 500, 200], $sizes);
    }

    public function testItSkipsRowsAlreadyInTheTargetStatusSoARepeatedRunMakesProgress(): void
    {
        $this->request->method('getParam')->willReturnMap($this->massParams('2'));
        $this->writer->method('change')->willReturn(1);

        $this->massStatus([1])->execute();

        $this->assertSame(['main_table.status <> ?' => 2], $this->selectionWhere);
    }

    public function testItPassesTheMassActionSelectionToTheGridSelection(): void
    {
        $this->request->method('getParam')->willReturnMap($this->massParams('2'));
        $this->writer->method('change')->willReturn(1);

        $this->massStatus([4])->execute();

        $this->assertSame([['4'], 'false'], $this->passed);
    }

    public function testItChangesTheStatusFromTheSubmissionView(): void
    {
        $writer = $this->writer = $this->createMock(StatusWriter::class);
        $this->request->method('getParam')->willReturnMap([['id', null, '7'], ['status', null, '1']]);
        $writer->expects($this->once())->method('change')->with([7], 1, 'admin')->willReturn(1);
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/view', ['id' => 7])->willReturnSelf();
        $this->expectingMessages()->expects($this->once())->method('addSuccessMessage');

        $this->assertSame($this->redirect, $this->saveStatus()->execute());
    }

    public function testItRejectsAnUnknownStatusFromTheSubmissionView(): void
    {
        $writer = $this->writer = $this->createMock(StatusWriter::class);
        $this->request->method('getParam')->willReturnMap([['id', null, '7'], ['status', null, 'x']]);
        $writer->expects($this->never())->method('change');
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');

        $this->saveStatus()->execute();
    }

    public function testItReportsAMissingSubmissionAndGoesBackToTheGrid(): void
    {
        $this->request->method('getParam')->willReturnMap([['id', null, '7'], ['status', null, '1']]);
        $this->writer->method('change')->willReturn(0);
        $this->expectingMessages()->expects($this->once())->method('addErrorMessage');
        $this->expectingMessages()->expects($this->never())->method('addSuccessMessage');
        $this->expectingRedirect()->expects($this->once())->method('setPath')->with('*/*/')->willReturnSelf();

        $this->saveStatus()->execute();
    }
}
