<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class SavedRule extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('furan_formbuilder_rule', 'rule_id');
    }
}
