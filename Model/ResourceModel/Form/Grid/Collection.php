<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\ResourceModel\Form\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class Collection extends SearchResult
{
    protected function _initSelect()
    {
        parent::_initSelect();
        // Forms being deleted in the background (Form\Purger) are already gone as far as the admin is concerned.
        $this->getSelect()->where('main_table.purge_requested_at IS NULL');
    }
}
