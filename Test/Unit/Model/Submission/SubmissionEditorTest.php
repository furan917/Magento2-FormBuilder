<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Model\Action\ActionRunner;
use Furan\FormBuilder\Model\Submission\EditTarget;
use Furan\FormBuilder\Model\Submission\NotEditableException;
use Furan\FormBuilder\Model\Submission\Result;
use Furan\FormBuilder\Model\Submission\SubmissionEditor;
use Furan\FormBuilder\Model\Submission\SubmissionUpdater;
use Furan\FormBuilder\Model\Upload\SubmissionFiles;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SubmissionEditorTest extends TestCase
{
    private function editor(
        ?SubmissionUpdater $updater = null,
        ?SubmissionRepositoryInterface $repository = null,
        ?ActionRunner $runner = null,
        ?SubmissionFiles $files = null,
        ?LoggerInterface $logger = null
    ): SubmissionEditor {
        return new SubmissionEditor(
            $updater ?? $this->createStub(SubmissionUpdater::class),
            $repository ?? $this->createStub(SubmissionRepositoryInterface::class),
            $runner ?? $this->createStub(ActionRunner::class),
            $files ?? $this->createStub(SubmissionFiles::class),
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    private function apply(SubmissionEditor $editor, EditTarget $target, array &$stored): ?Result
    {
        return $editor->apply($this->createStub(FormInterface::class), $target, [], [], [], $stored, 5);
    }

    public function testItEmptiesTheStoredFilesOnceTheUpdateOwnsThem(): void
    {
        $stored = ['f/a.pdf'];

        $this->assertNull($this->apply($this->editor(), new EditTarget(9, []), $stored));
        $this->assertSame([], $stored);
    }

    public function testItDeletesTheStoredFilesAndGivesTheTargetsRefusalWhenTheSubmissionIsNotEditable(): void
    {
        $updater = $this->createStub(SubmissionUpdater::class);
        $updater->method('update')->willThrowException(new NotEditableException());
        $files = $this->createMock(SubmissionFiles::class);
        $files->expects($this->once())->method('delete')->with(['f/a.pdf']);
        $lost = Result::refused('Gone', 400);
        $stored = ['f/a.pdf'];

        $result = $this->apply($this->editor($updater, files: $files), new EditTarget(9, [], $lost), $stored);

        $this->assertSame($lost, $result);
        $this->assertSame([], $stored);
    }

    public function testItGivesTheGenericRefusalWhenTheTargetHasNone(): void
    {
        $updater = $this->createStub(SubmissionUpdater::class);
        $updater->method('update')->willThrowException(new NotEditableException());
        $stored = [];

        $result = $this->apply($this->editor($updater), new EditTarget(9, []), $stored);

        $this->assertSame(400, $result?->getHttpCode());
        $this->assertSame('This answer can no longer be changed.', $result->getMessage());
    }

    public function testItPassesOnA403RefusalFromTheUpdate(): void
    {
        $updater = $this->createStub(SubmissionUpdater::class);
        $updater->method('update')->willThrowException(new NotEditableException('Not yours', 403));
        $stored = [];

        $result = $this->apply($this->editor($updater), new EditTarget(9, [], Result::refused('Gone', 400)), $stored);

        $this->assertSame(403, $result?->getHttpCode());
        $this->assertSame('Not yours', $result->getMessage());
    }

    public function testItRunsTheEditActionsAndReturnsTheStoredReference(): void
    {
        $submission = new SubmissionStub();
        $submission->setReference('ref-1');
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $repository->expects($this->once())->method('getById')->with(9)->willReturn($submission);
        $runner = $this->createMock(ActionRunner::class);
        $runner->expects($this->once())->method('run')
            ->with($this->anything(), $submission, ['name' => 'Ann'], true);

        $reference = $this->editor(repository: $repository, runner: $runner)
            ->complete($this->createStub(FormInterface::class), 9, ['name' => 'Ann']);

        $this->assertSame('ref-1', $reference);
    }

    public function testItLogsAnActionFailureAndStillReturnsTheReference(): void
    {
        $submission = new SubmissionStub();
        $submission->setReference('ref-1');
        $repository = $this->createStub(SubmissionRepositoryInterface::class);
        $repository->method('getById')->willReturn($submission);
        $runner = $this->createStub(ActionRunner::class);
        $runner->method('run')->willThrowException(new \RuntimeException('boom'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $reference = $this->editor(repository: $repository, runner: $runner, logger: $logger)
            ->complete($this->createStub(FormInterface::class), 9, []);

        $this->assertSame('ref-1', $reference);
    }

    public function testItAnswersWithoutAReferenceWhenTheEditedSubmissionCannotBeLoaded(): void
    {
        $repository = $this->createStub(SubmissionRepositoryInterface::class);
        $repository->method('getById')->willThrowException(new \RuntimeException('gone'));
        $runner = $this->createMock(ActionRunner::class);
        $runner->expects($this->never())->method('run');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->assertNull(
            $this->editor(repository: $repository, runner: $runner, logger: $logger)
                ->complete($this->createStub(FormInterface::class), 9, [])
        );
    }
}
