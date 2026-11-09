<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\ResourceModel\FieldGroup;

use Furan\FormBuilder\Model\FieldGroup;
use Furan\FormBuilder\Model\ResourceModel\FieldGroup as Resource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'group_id';

    protected function _construct()
    {
        $this->_init(FieldGroup::class, Resource::class);
    }
}
