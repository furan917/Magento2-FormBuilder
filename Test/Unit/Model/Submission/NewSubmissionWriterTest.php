<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\SubmissionInput;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Model\Stats\Journal;
use Furan\FormBuilder\Model\Stats\SeriesRegistry;
use Furan\FormBuilder\Model\Submission\NewSubmissionWriter;
use Furan\FormBuilder\Model\Survey\AlreadyAnsweredException;
use Furan\FormBuilder\Model\Survey\RespondentRecorder;
use Furan\FormBuilder\Model\Survey\Respondents;
use Furan\FormBuilder\Model\Upload\FileRegistry;
use Furan\FormBuilder\Model\Webhook\SubmissionDeliveries;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NewSubmissionWriterTest extends TestCase
{
    /** @var list<string> */
    private array $order = [];

    private function writer(?AlreadyAnsweredException $duplicate = null, ?\Throwable $wakeFailure = null, ?LoggerInterface $logger = null): NewSubmissionWriter
    {
        $registry = $this->createStub(FileRegistry::class);
        $registry->method('saveWith')->willReturnCallback(function (array $paths, callable $save): void {
            $this->order[] = 'begin:' . implode(',', $paths);
            $save();
            $this->order[] = 'commit';
        });
        $repository = $this->createStub(SubmissionRepositoryInterface::class);
        $repository->method('save')->willReturnCallback(function ($submission) {
            $this->order[] = 'save';
            $submission->id = 11;
            return $submission;
        });
        $series = $this->createStub(SeriesRegistry::class);
        $series->method('specsFor')->willReturnCallback(function (): array {
            $this->order[] = 'specs';
            return [];
        });
        $journal = $this->createStub(Journal::class);
        $journal->method('add')->willReturnCallback(function (): void {
            $this->order[] = 'journal';
        });
        $respondents = $this->createStub(Respondents::class);
        $respondents->method('customerKey')->willReturn('c0');
        $respondents->method('emailKey')->willReturn('e');
        $respondents->method('assertNotAnswered')->willReturnCallback(function () use ($duplicate): void {
            if ($duplicate !== null) {
                throw $duplicate;
            }
        });
        $deliveries = $this->createStub(SubmissionDeliveries::class);
        $deliveries->method('record')->willReturnCallback(function (): array {
            $this->order[] = 'record';
            return [5];
        });
        $deliveries->method('wake')->willReturnCallback(function (array $ids) use ($wakeFailure): void {
            if ($wakeFailure !== null) {
                throw $wakeFailure;
            }
            $this->order[] = 'wake:' . implode(',', $ids);
        });

        return new NewSubmissionWriter(
            $registry,
            $repository,
            $series,
            $journal,
            new RespondentRecorder($respondents),
            $deliveries,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    private function form(): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(7);

        return $form;
    }

    public function testItSavesTheSubmissionWithItsFilesAndWakesTheDeliveriesAfterTheCommit(): void
    {
        $this->writer()->write($this->form(), new SubmissionStub(), [], [], [], new SubmissionInput(), ['f/a.pdf']);

        $this->assertSame(
            ['specs', 'begin:f/a.pdf', 'save', 'journal', 'record', 'commit', 'wake:5'],
            $this->order
        );
    }

    public function testItKeepsASavedSubmissionAndLogsOnlyTheClassWhenTheQueueWakeFails(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->callback(
            static fn (string $message): bool => str_contains($message, \RuntimeException::class)
                && !str_contains($message, 'secret@x.test')
        ));
        $writer = $this->writer(wakeFailure: new \RuntimeException('secret@x.test'), logger: $logger);

        $writer->write($this->form(), new SubmissionStub(), [], [], [], new SubmissionInput(), ['f/a.pdf']);

        $this->assertContains('commit', $this->order);
    }

    public function testItLetsADuplicateSurveyAnswerEscapeBeforeWakingAnyDelivery(): void
    {
        $writer = $this->writer(new AlreadyAnsweredException());

        try {
            $writer->write(
                $this->form(),
                new SubmissionStub(),
                ['survey_mode' => 'once', 'guest_dedupe' => true],
                [],
                ['mail' => 'g@x.test', 'x' => 1],
                new SubmissionInput(customerEmail: 'g@x.test'),
                []
            );
            $this->fail('expected a duplicate');
        } catch (AlreadyAnsweredException) {
            $this->assertNotContains('wake:5', $this->order);
        }
    }
}
