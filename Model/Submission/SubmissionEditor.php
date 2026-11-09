<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Model\Action\ActionRunner;
use Furan\FormBuilder\Model\Upload\SubmissionFiles;
use Psr\Log\LoggerInterface;

class SubmissionEditor
{
    public function __construct(
        private readonly SubmissionUpdater $submissionUpdater,
        private readonly SubmissionRepositoryInterface $submissionRepository,
        private readonly ActionRunner $actionRunner,
        private readonly SubmissionFiles $files,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @param array<int, array{name: string, label: string, type: string, value: mixed}> $payload
     * @param string[] $storedFiles files stored for this request; emptied once the submission owns them
     * @return Result|null the refusal, or null once the edit is saved
     */
    public function apply(
        FormInterface $form,
        EditTarget $target,
        array $fields,
        array $values,
        array $payload,
        array &$storedFiles,
        int $customerId
    ): ?Result {
        try {
            $this->submissionUpdater->update(
                $form,
                (int)$target->submissionId,
                $customerId,
                $fields,
                $values,
                $payload,
                $storedFiles
            );
        } catch (NotEditableException $e) {
            $this->files->delete($storedFiles);
            $storedFiles = [];
            if ($e->getCode() === 403) {
                return Result::refused($e->getMessage(), 403);
            }
            return $target->lostRefusal ?? Result::refused((string)__('This answer can no longer be changed.'), 400);
        }
        $storedFiles = [];
        return null;
    }

    /**
     * The edit is already saved: a failure here is logged, never turned into an error response.
     *
     * @param array<string, mixed> $values
     * @return string|null the stored submission's reference, null when it could not be loaded
     */
    public function complete(FormInterface $display, int $submissionId, array $values): ?string
    {
        try {
            $stored = $this->submissionRepository->getById($submissionId);
        } catch (\Throwable $e) {
            $this->logger->error('Edit actions failed: ' . $e->getMessage(), ['exception' => $e]);
            return null;
        }
        try {
            $this->actionRunner->run($display, $stored, $values, true);
        } catch (\Throwable $e) {
            $this->logger->error('Edit actions failed: ' . $e->getMessage(), ['exception' => $e]);
        }
        return $stored->getReference();
    }
}
