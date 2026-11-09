<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\SubmissionInput;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Model\Stats\Journal;
use Furan\FormBuilder\Model\Stats\SeriesRegistry;
use Furan\FormBuilder\Model\Survey\AlreadyAnsweredException;
use Furan\FormBuilder\Model\Survey\RespondentRecorder;
use Furan\FormBuilder\Model\Upload\FileRegistry;
use Furan\FormBuilder\Model\Webhook\SubmissionDeliveries;
use Psr\Log\LoggerInterface;

class NewSubmissionWriter
{
    public function __construct(
        private readonly FileRegistry $fileRegistry,
        private readonly SubmissionRepositoryInterface $submissionRepository,
        private readonly SeriesRegistry $seriesRegistry,
        private readonly Journal $journal,
        private readonly RespondentRecorder $respondentRecorder,
        private readonly SubmissionDeliveries $deliveries,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $settings
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @param string[] $storedFiles files the saved submission takes over
     * @throws AlreadyAnsweredException nothing is saved then
     */
    public function write(
        FormInterface $form,
        SubmissionInterface $submission,
        array $settings,
        array $fields,
        array $values,
        SubmissionInput $input,
        array $storedFiles
    ): void {
        $formId = (int)$form->getId();
        // Autocommit, before the submit transaction: a new spec's insert lock must not outlive this call.
        $specs = $this->seriesRegistry->specsFor($formId, $fields);
        $deliveryIds = [];
        $this->fileRegistry->saveWith(
            $storedFiles,
            function () use ($submission, $formId, $settings, $values, $specs, $input, &$deliveryIds): int {
                $this->submissionRepository->save($submission);
                $id = (int)$submission->getId();
                $this->journal->add($submission, $specs);
                $this->respondentRecorder->record($formId, $settings, $values, $id, $input);
                $deliveryIds = $this->deliveries->record($id, $formId, $submission->getStoreId(), 'submission.created');
                return $id;
            }
        );
        $this->wake($deliveryIds);
    }

    /**
     * Must not throw: the caller deletes files on error. Only the class is logged; the message can carry visitor data.
     *
     * @param int[] $deliveryIds
     */
    private function wake(array $deliveryIds): void
    {
        try {
            $this->deliveries->wake($deliveryIds);
        } catch (\Throwable $e) {
            $this->logger->error('Webhook queue wake failed: ' . $e::class);
        }
    }
}
