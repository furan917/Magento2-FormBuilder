<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Account;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Model\Privacy\Eraser;
use Furan\FormBuilder\Model\ResourceModel\Submission as SubmissionResource;
use Furan\FormBuilder\Model\Survey\Respondents;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;

/** A customer's own deletes: survey respondent rows are detached, not removed, so the survey stays answered. */
class CustomerSubmissionDeleter
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Respondents $respondents,
        private readonly SubmissionRepositoryInterface $submissionRepository,
        private readonly SubmissionResource $submissions,
        private readonly FormRepositoryInterface $formRepository,
        private readonly Eraser $eraser,
        private readonly SubmissionPolicy $policy
    ) {
    }

    /** Callers check ownership and the form's delete setting. */
    public function delete(int $submissionId): void
    {
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $this->respondents->detach([$submissionId]);
            $this->submissionRepository->deleteById($submissionId);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Submissions of forms that disallow account delete are kept.
     *
     * @return array{deleted: int, kept: int}
     */
    public function deleteAll(int $customerId): array
    {
        $keepFormIds = $this->keepFormIds($customerId);
        $deleted = $this->eraser->eraseCustomer($customerId, $keepFormIds, true);

        return ['deleted' => $deleted, 'kept' => $this->submissions->countForCustomer($customerId, $keepFormIds)];
    }

    /** @return int[] */
    private function keepFormIds(int $customerId): array
    {
        $keep = [];
        foreach ($this->submissions->customerFormIds($customerId) as $formId) {
            try {
                $settings = $this->formRepository->getById($formId)->getSettings();
            } catch (NoSuchEntityException) {
                continue;
            }
            if (!$this->policy->canDelete($settings)) {
                $keep[] = $formId;
            }
        }
        return $keep;
    }
}
