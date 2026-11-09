<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model;

use Magento\Framework\Model\AbstractModel;

class SavedRule extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(ResourceModel\SavedRule::class);
    }
}
