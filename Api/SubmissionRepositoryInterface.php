<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\Data\SubmissionSearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;

/** @api */
interface SubmissionRepositoryInterface
{
    /**
     * @throws CouldNotSaveException
     */
    public function save(SubmissionInterface $submission): SubmissionInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $submissionId): SubmissionInterface;

    /**
     * One unique-index lookup; the reference may use any hyphenation and case.
     *
     * @throws NoSuchEntityException also for a malformed reference
     */
    public function getByReference(string $reference): SubmissionInterface;

    /**
     * One keyset page, no OFFSET and no COUNT: currentPage stays 1; page on with a submission_id gt (or lt for DESC)
     * filter past the last id. Sorted by submission_id only (it follows created_at). pageSize defaults to 100, at
     * most 500. Filters, one per group (AND only), each on an indexed column: submission_id (eq, in, gt, gteq, lt,
     * lteq), form_id, status, store_id, customer_id, is_read (eq), created_at (gt, gteq, lt, lteq; the matches of a
     * range are sorted, so bound a wide range with submission_id too). updated_at (gt, gteq, lt, lteq) has no index
     * and needs an eq filter on form_id, status, store_id, customer_id or is_read with it. total_count is the number of items returned; getHasMore() says if more follow.
     *
     * @throws InputException for any other field, condition, sort or page
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SubmissionSearchResultsInterface;

    /**
     * Dispatches furan_formbuilder_submission_delete_before so stored files are removed.
     *
     * @throws CouldNotDeleteException
     */
    public function delete(SubmissionInterface $submission): bool;

    /**
     * @throws NoSuchEntityException
     * @throws CouldNotDeleteException
     */
    public function deleteById(int $submissionId): bool;
}
