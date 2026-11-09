<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\ResourceModel\Submission\Grid;

use Magento\Framework\DB\Select;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class Collection extends SearchResult
{
    /** The grid never shows more than this many matching rows; mass actions are not limited by it. */
    public const CAP = 10000;

    /** The select as it was before the page window replaced its filters; null while no window is applied. */
    private ?Select $unwindowed = null;

    public const COLUMNS = [
        'submission_id', 'form_id', 'store_id', 'customer_id', 'summary', 'is_read', 'created_at',
        'status', 'customer_group_id', 'reference'
    ];

    protected function _initSelect()
    {
        parent::_initSelect();
        // Leaving out payload_json keeps sorting in memory; with it MySQL sorts 1M rows on disk.
        $this->getSelect()->reset(Select::COLUMNS)->columns(self::COLUMNS, 'main_table');
    }

    /** Ids rise with insert time and created_at is set on insert, so sorting by id seeks the (column, id) indexes. */
    public function setOrder($field, $direction = self::SORT_ORDER_DESC)
    {
        return parent::setOrder($this->sortColumn($field), $direction);
    }

    public function addOrder($field, $direction = self::SORT_ORDER_DESC)
    {
        return parent::addOrder($this->sortColumn($field), $direction);
    }

    public function unshiftOrder($field, $direction = self::SORT_ORDER_DESC)
    {
        return parent::unshiftOrder($this->sortColumn($field), $direction);
    }

    /** A reloaded collection must filter and window again, not reuse the previous page's ids. */
    public function clear()
    {
        $this->restoreUnwindowedSelect();

        return parent::clear();
    }

    protected function _reset()
    {
        $this->unwindowed = null;

        return parent::_reset();
    }

    public function getSize()
    {
        if ($this->_totalRecords === null) {
            $this->_totalRecords = min(self::CAP, (int)$this->getConnection()->fetchOne($this->boundedCountSql(), $this->_bindParams));
        }

        return (int)$this->_totalRecords;
    }

    /** Windows the page by id instead of OFFSET; only at load time, so the select mass actions walk stays whole. */
    protected function _renderLimit()
    {
        $pageSize = (int)$this->_pageSize;
        // Collection::load() renders the limit twice.
        if ($pageSize < 1 || $this->unwindowed !== null) {
            return $this;
        }
        // Counted before the id filter below changes the select.
        $size = $this->getSize();
        $offset = ($this->getCurPage() - 1) * $pageSize;
        // No sort chosen means newest first, which the cap notice promises.
        $direction = (string)(reset($this->_orders) ?: Select::SQL_DESC);
        $tieBreak = 'main_table.submission_id ' . $direction;
        $sortedById = isset($this->_orders['submission_id']);

        $ids = [];
        if ($offset < $size) {
            $window = clone $this->getSelect();
            if (!$sortedById) {
                $window->order($tieBreak);
            }
            $window->reset(Select::COLUMNS)
                ->columns('main_table.submission_id')
                ->limit(min($offset + $pageSize, self::CAP));
            $ids = array_slice(array_map('intval', $this->getConnection()->fetchCol($window, $this->_bindParams)), $offset, $pageSize);
        }
        $this->unwindowed = clone $this->getSelect();
        $page = $this->getSelect()->reset(Select::WHERE);
        if (!$sortedById) {
            $page->order($tieBreak);
        }
        if ($ids === []) {
            $page->where('1 = 0');
        } else {
            $page->where('main_table.submission_id IN (?)', $ids);
        }

        return $this;
    }

    private function sortColumn(string $field): string
    {
        return $field === 'created_at' ? 'submission_id' : $field;
    }

    private function restoreUnwindowedSelect(): void
    {
        if ($this->unwindowed !== null) {
            $this->_select = $this->unwindowed;
            $this->unwindowed = null;
        }
    }

    /** Counts the rows of an id select, reading at most one past the cap. */
    public static function cappedCountSql(Select $select): string
    {
        $ids = clone $select;
        $ids->reset(Select::ORDER)
            ->reset(Select::COLUMNS)
            ->columns('main_table.submission_id')
            ->limit(self::CAP + 1);

        return 'SELECT COUNT(*) FROM (' . $ids . ') AS t';
    }

    private function boundedCountSql(): string
    {
        $this->_renderFilters();

        return self::cappedCountSql($this->getSelect());
    }
}
