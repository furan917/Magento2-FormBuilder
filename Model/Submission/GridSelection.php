<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Model\ResourceModel\Submission\KeysetIndex;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Submission ids picked in the admin grid, in keyset batches. Filter::getCollection() is avoided on purpose:
 * for "select all" it loads every matching grid row into memory and then filters by one giant IN list.
 */
class GridSelection
{
    public function __construct(
        private readonly Filter $filter,
        private readonly FilterBuilder $filterBuilder,
        private readonly KeysetIndex $keysetIndex
    ) {
    }

    /**
     * @param mixed $selected the posted `selected` ids
     * @param mixed $excluded the posted `excluded` ids, or 'false' for select all
     * @param array<string, mixed> $where extra conditions, e.g. rows not yet in the target state
     * @return \Generator<int, int[]>
     * @throws LocalizedException
     */
    public function batches(int $size, mixed $selected, mixed $excluded, array $where = []): \Generator
    {
        $idFilter = $this->idFilter($selected, $excluded);
        $component = $this->filter->getComponent();
        // Request-bound by Magento's listing contract: reads namespace, filters and search.
        $this->filter->prepareComponent($component);
        $dataProvider = $component->getContext()->getDataProvider();
        if ($idFilter !== null) {
            $this->filterBuilder->setConditionType($idFilter[0])
                ->setField($dataProvider->getPrimaryFieldName())
                ->setValue($idFilter[1]);
            $dataProvider->addFilter($this->filterBuilder->create());
        }
        /** @var SearchResult $searchResult */
        $searchResult = $dataProvider->getSearchResult();
        $base = clone $searchResult->getSelect();
        $base->reset(Select::COLUMNS)
            ->reset(Select::ORDER)
            ->reset(Select::LIMIT_COUNT)
            ->reset(Select::LIMIT_OFFSET);
        $base->columns('main_table.submission_id')
            ->order('main_table.submission_id ASC')
            ->limit($size);
        foreach ($where as $condition => $value) {
            $base->where($condition, $value);
        }
        $connection = $base->getConnection();
        $hint = $this->hint($base);
        $after = 0;
        do {
            $page = clone $base;
            $page->where('main_table.submission_id > ?', $after);
            $sql = (string)$page;
            if ($hint !== '') {
                $sql = (string)preg_replace('/ AS `main_table`/', ' AS `main_table`' . $hint, $sql, 1);
            }
            $ids = array_map('intval', $connection->fetchCol($sql));
            if ($ids === []) {
                return;
            }
            yield $ids;
            $after = (int)end($ids);
        } while (count($ids) >= $size);
    }

    /**
     * @return array{string, int[]}|null condition type and ids; null for select all
     * @throws LocalizedException
     */
    private function idFilter(mixed $selected, mixed $excluded): ?array
    {
        if ($excluded === 'false') {
            return null;
        }
        $filter = match (true) {
            is_array($excluded) && $excluded !== [] => ['nin', $this->ids($excluded)],
            is_array($selected) && $selected !== [] => ['in', $this->ids($selected)],
            default => ['', []],
        };
        if ($filter[1] === []) {
            throw new LocalizedException(__('An item needs to be selected. Select and try again.'));
        }

        return $filter;
    }

    /**
     * @param array<mixed> $values
     * @return int[]
     */
    private function ids(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $ids[] = (int)$value;
            }
        }

        return $ids;
    }

    /** The framework select has no index hints; only a plain single-table select is hinted. */
    private function hint(Select $select): string
    {
        if (count($select->getPart(Select::FROM)) !== 1) {
            return '';
        }
        $where = implode(' ', $select->getPart(Select::WHERE));
        return str_contains($where, 'form_id') ? $this->keysetIndex->forForm() : $this->keysetIndex->forPrimary();
    }
}
