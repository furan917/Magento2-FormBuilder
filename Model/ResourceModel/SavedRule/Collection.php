<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\ResourceModel\SavedRule;

use Furan\FormBuilder\Model\ResourceModel\SavedRule as Resource;
use Furan\FormBuilder\Model\SavedRule;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'rule_id';

    protected function _construct()
    {
        $this->_init(SavedRule::class, Resource::class);
    }
}
