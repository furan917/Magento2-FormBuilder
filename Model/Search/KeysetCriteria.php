<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Search;

use Furan\FormBuilder\Api\Data\KeysetSearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\InputException;

/**
 * Reads search criteria for keyset paging: allowlisted fields and conditions, AND only, no OFFSET, no COUNT.
 */
class KeysetCriteria
{
    public const DEFAULT_PAGE_SIZE = 100;
    public const MAX_PAGE_SIZE = 500;

    /**
     * @param array<string, list<string>> $filterable field => allowed condition types
     * @return list<array{field: string, condition: string, value: string|list<string>}>
     * @throws InputException
     */
    public function filters(SearchCriteriaInterface $criteria, array $filterable): array
    {
        $filters = [];
        foreach ($criteria->getFilterGroups() as $group) {
            $groupFilters = $group->getFilters() ?? [];
            if (count($groupFilters) > 1) {
                throw new InputException(__('Put each filter in its own filter group; OR filters are not supported.'));
            }
            foreach ($groupFilters as $filter) {
                $field = (string)$filter->getField();
                $condition = (string)$filter->getConditionType();
                if (!isset($filterable[$field])) {
                    throw new InputException(__('Filtering by "%1" is not supported.', $field));
                }
                if (!in_array($condition, $filterable[$field], true)) {
                    throw new InputException(__('The "%1" condition is not supported for "%2".', $condition, $field));
                }
                $filters[] = [
                    'field' => $field,
                    'condition' => $condition,
                    'value' => $this->value($filter->getValue(), $condition),
                ];
            }
        }
        return $filters;
    }

    /**
     * @param list<string> $sortable fields besides the id
     * @return array<string, string> field => ASC|DESC, the id last
     * @throws InputException
     */
    public function sortOrders(SearchCriteriaInterface $criteria, array $sortable, string $idField): array
    {
        $orders = [];
        $direction = SortOrder::SORT_ASC;
        foreach ($criteria->getSortOrders() ?? [] as $sortOrder) {
            $field = (string)$sortOrder->getField();
            if ($field !== $idField && !in_array($field, $sortable, true)) {
                throw new InputException(__('Sorting by "%1" is not supported.', $field));
            }
            $direction = strtoupper((string)$sortOrder->getDirection()) === SortOrder::SORT_DESC
                ? SortOrder::SORT_DESC
                : SortOrder::SORT_ASC;
            if ($field === $idField) {
                // The id is unique, so nothing after it changes the order.
                $orders[$idField] = $direction;
                return $orders;
            }
            $orders[$field] = $direction;
        }
        $orders[$idField] = $direction;
        return $orders;
    }

    /**
     * @throws InputException for any page after the first
     */
    public function pageSize(SearchCriteriaInterface $criteria, string $idField): int
    {
        if ((int)$criteria->getCurrentPage() > 1) {
            throw new InputException(__(
                'currentPage above 1 is not supported. Page with a "%1" "gt" filter, sorted by %1.',
                $idField
            ));
        }
        $size = (int)$criteria->getPageSize();
        return $size < 1 ? self::DEFAULT_PAGE_SIZE : min($size, self::MAX_PAGE_SIZE);
    }

    /**
     * Applies the filters, the order and a LIMIT of one row past the page.
     *
     * @param list<array{field: string, condition: string, value: string|list<string>}> $filters
     * @param array<string, string> $orders
     */
    public function apply(AbstractDb $collection, array $filters, array $orders, int $pageSize): void
    {
        foreach ($filters as $filter) {
            $collection->addFieldToFilter('main_table.' . $filter['field'], [$filter['condition'] => $filter['value']]);
        }
        $select = $collection->getSelect();
        foreach ($orders as $field => $direction) {
            $select->order('main_table.' . $field . ' ' . $direction);
        }
        // Never setPageSize(): loading would add an OFFSET and a COUNT(*).
        $select->limit($pageSize + 1);
    }

    /**
     * @template T of KeysetSearchResultsInterface
     * @param T $results
     * @param array<mixed> $rows up to one row past the page
     * @return T
     */
    public function fill(
        KeysetSearchResultsInterface $results,
        SearchCriteriaInterface $criteria,
        array $rows,
        int $pageSize
    ): KeysetSearchResultsInterface {
        $items = array_slice(array_values($rows), 0, $pageSize);
        $results->setSearchCriteria($criteria);
        $results->setItems($items);
        $results->setTotalCount(count($items));
        $results->setHasMore(count($rows) > $pageSize);
        return $results;
    }

    /**
     * @return string|list<string>
     */
    private function value(mixed $value, string $condition): string|array
    {
        if ($condition !== 'in') {
            return is_scalar($value) ? (string)$value : '';
        }
        $values = is_array($value) ? $value : explode(',', is_scalar($value) ? (string)$value : '');
        return array_values(array_map(static fn ($v): string => trim(is_scalar($v) ? (string)$v : ''), $values));
    }
}
