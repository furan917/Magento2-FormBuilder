<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\FieldGroup;

/**
 * Save as group: always creates a new group from the posted JSON copy.
 */
class Create extends AbstractBuilderEndpoint
{
    public const ADMIN_RESOURCE = 'Furan_FormBuilder::field_groups_edit';
    private const VIEW_RESOURCE = 'Furan_FormBuilder::field_groups';

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE)
            && $this->_authorization->isAllowed(self::VIEW_RESOURCE);
    }

    public function execute()
    {
        return $this->respond($this->groups->create($this->getRequest()->getParam('payload')));
    }
}
