<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\FormSearchResultsInterface;
use Furan\FormBuilder\Api\Data\FormSearchResultsInterfaceFactory;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Furan\FormBuilder\Model\ResourceModel\Form\CollectionFactory;
use Furan\FormBuilder\Model\Schema\SchemaMigrator;
use Furan\FormBuilder\Model\Search\KeysetCriteria;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

class FormRepository implements FormRepositoryInterface
{
    private const RANGE = ['eq', 'in', 'gt', 'gteq', 'lt', 'lteq'];
    private const FILTERABLE = [
        'form_id' => self::RANGE,
        'identifier' => ['eq', 'in', 'like'],
        'title' => ['eq', 'like'],
        'is_active' => ['eq'],
        'created_at' => self::RANGE,
        'updated_at' => self::RANGE,
        'store_id' => ['eq'],
    ];
    private const SORTABLE = ['identifier', 'title', 'is_active', 'created_at', 'updated_at'];

    public function __construct(
        private readonly FormResource $resource,
        private readonly FormFactory $formFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly SchemaMigrator $schemaMigrator,
        private readonly LoggerInterface $logger,
        private readonly FormSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly KeysetCriteria $keyset
    ) {
    }

    public function save(FormInterface $form): FormInterface
    {
        try {
            $this->resource->save($form); // @phpstan-ignore argument.type (every implementation of the Api model is an AbstractModel)
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save the form: %1', $e->getMessage()), $e);
        }
        return $form;
    }

    public function getById(int $formId): FormInterface
    {
        $form = $this->formFactory->create();
        $this->resource->load($form, $formId);
        if (!$form->getId()) {
            throw new NoSuchEntityException(__('Form with id "%1" does not exist.', $formId));
        }
        return $form;
    }

    public function getActiveByIdentifier(string $identifier, int $storeId): FormInterface
    {
        return $this->getActiveBy('identifier', $identifier, $storeId);
    }

    public function getActiveById(int $formId, int $storeId): FormInterface
    {
        return $this->getActiveBy('form_id', $formId, $storeId);
    }

    public function getList(SearchCriteriaInterface $searchCriteria): FormSearchResultsInterface
    {
        $pageSize = $this->keyset->pageSize($searchCriteria, 'form_id');
        $orders = $this->keyset->sortOrders($searchCriteria, self::SORTABLE, 'form_id');
        $filters = [];
        $collection = $this->collectionFactory->create();
        foreach ($this->keyset->filters($searchCriteria, self::FILTERABLE) as $filter) {
            if ($filter['field'] === 'store_id') {
                $collection->addStoreFilter(is_string($filter['value']) ? (int)$filter['value'] : 0);
            } else {
                $filters[] = $filter;
            }
        }
        $this->keyset->apply($collection, $filters, $orders, $pageSize);
        $results = $this->keyset->fill(
            $this->searchResultsFactory->create(),
            $searchCriteria,
            $collection->getItems(),
            $pageSize
        );
        /** @var Form[] $forms */
        $forms = $results->getItems();
        if ($forms !== []) {
            $translations = $this->resource->lookupTranslationsFor(
                array_map(static fn (Form $form): int => (int)$form->getId(), $forms)
            );
            foreach ($forms as $form) {
                $form->setTranslations($translations[(int)$form->getId()] ?? []);
            }
        }
        return $results;
    }

    public function delete(FormInterface $form): bool
    {
        try {
            $this->resource->delete($form); // @phpstan-ignore argument.type (every implementation of the Api model is an AbstractModel)
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(__('Could not delete the form.'), $e);
        }
        return true;
    }

    public function deleteById(int $formId): bool
    {
        return $this->delete($this->getById($formId));
    }

    private function getActiveBy(string $column, string|int $value, int $storeId): FormInterface
    {
        $collection = $this->collectionFactory->create()->addFieldToSelect('form_id');
        // A scalar is an equality condition for Magento; its docblock only lists array|string|null.
        // @phpstan-ignore argument.type
        $collection->addFieldToFilter('main_table.' . $column, $value)
            // @phpstan-ignore argument.type
            ->addFieldToFilter('main_table.is_active', 1)
            ->addStoreFilter($storeId);
        // Not setPageSize(1): getFirstItem() would then run a COUNT(*) first.
        $collection->getSelect()->limit(1);
        /** @var Form $first */
        $first = $collection->getFirstItem();
        $id = (int)$first->getId();
        if (!$id) {
            throw new NoSuchEntityException(__('The form "%1" does not exist.', $value));
        }
        $form = $this->getById($id);
        // The storefront must not guess at a format written by a newer module version.
        $schema = $form->getSchema();
        if (!$this->schemaMigrator->isSupported($schema)) {
            $this->logger->warning(sprintf(
                'Form %d has schema version %d, newer than supported: not served.',
                $id,
                $this->schemaMigrator->versionOf($schema)
            ));
            throw new NoSuchEntityException(__('The form "%1" does not exist.', $value));
        }
        return $form;
    }
}
