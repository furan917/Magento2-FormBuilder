<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\ResourceModel\Submission;

use Furan\FormBuilder\Model\ResourceModel\Submission as SubmissionResource;
use Furan\FormBuilder\Model\Submission;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'submission_id';

    protected function _construct()
    {
        $this->_init(Submission::class, SubmissionResource::class);
    }
}
