<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\FieldGroup;

/**
 * The stored copy of one group; the builder remaps ids and names and the form Save normalises the result.
 */
class Insert extends AbstractBuilderEndpoint
{
    public const ADMIN_RESOURCE = 'Furan_FormBuilder::field_groups';

    public function execute()
    {
        return $this->respond($this->groups->fetch((int)$this->getRequest()->getParam('id')));
    }
}
