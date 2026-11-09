<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\FieldGroup;

/**
 * One page of group names for the builder palette (keyset, the fields are not loaded).
 */
class Palette extends AbstractBuilderEndpoint
{
    public const ADMIN_RESOURCE = 'Furan_FormBuilder::field_groups';

    public function execute()
    {
        return $this->respond($this->groups->palette((int)$this->getRequest()->getParam('after')));
    }
}
