<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Model\ResourceModel\Submission\Grid\Collection;

/** The grid collection without the framework select setup, which needs the ObjectManager. */
class BlankSelectGridCollection extends Collection
{
    protected function _initSelect()
    {
    }
}
