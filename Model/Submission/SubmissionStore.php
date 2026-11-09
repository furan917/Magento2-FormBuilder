<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Store view whose mail templates, sender and limits apply to a submission. Its store_id is NULL once the store
 * view is deleted, so it falls back to the form's first store view, or the default one for "all store views".
 */
class SubmissionStore
{
    public function __construct(private readonly StoreManagerInterface $storeManager)
    {
    }

    public function resolve(SubmissionInterface $submission, FormInterface $form): int
    {
        $storeId = (int)$submission->getStoreId();
        if ($storeId > 0) {
            return $storeId;
        }
        $assigned = array_map('intval', $form->getStoreIds());
        if (!in_array(0, $assigned, true)) {
            $assigned = array_filter($assigned, static fn (int $id): bool => $id > 0);
            if ($assigned !== []) {
                return min($assigned);
            }
        }

        return (int)$this->storeManager->getDefaultStoreView()?->getId();
    }
}
