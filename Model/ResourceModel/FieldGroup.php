<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class FieldGroup extends AbstractDb
{
    public const TABLE = 'furan_formbuilder_field_group';

    protected function _construct()
    {
        $this->_init(self::TABLE, 'group_id');
    }
}
