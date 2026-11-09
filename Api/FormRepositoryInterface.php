<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\FormSearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;

/** @api */
interface FormRepositoryInterface
{
    /**
     * @throws CouldNotSaveException
     */
    public function save(FormInterface $form): FormInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $formId): FormInterface;

    /**
     * Active and assigned to the store (or to all stores).
     *
     * @throws NoSuchEntityException
     */
    public function getActiveByIdentifier(string $identifier, int $storeId): FormInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getActiveById(int $formId, int $storeId): FormInterface;

    /**
     * One keyset page, no OFFSET: currentPage stays 1; page on with a form_id gt (or lt for DESC) filter past the
     * last id, sorted by form_id. pageSize defaults to 100, at most 500; form_id is always the last sort.
     * Filters, one per group (AND only): form_id (eq, in, gt, gteq, lt, lteq), identifier (eq, in, like),
     * title (eq, like), is_active (eq), created_at and updated_at (eq, in, gt, gteq, lt, lteq), store_id (eq,
     * also matches forms on all store views). Sorts: form_id, identifier, title, is_active, created_at, updated_at.
     *
     * @throws InputException for any other field, condition, sort or page
     */
    public function getList(SearchCriteriaInterface $searchCriteria): FormSearchResultsInterface;

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(FormInterface $form): bool;

    /**
     * @throws NoSuchEntityException
     * @throws CouldNotDeleteException
     */
    public function deleteById(int $formId): bool;
}
