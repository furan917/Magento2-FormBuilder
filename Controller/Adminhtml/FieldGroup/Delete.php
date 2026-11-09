<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\FieldGroup;

use Furan\FormBuilder\Model\FieldGroup\FieldGroupManager;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Furan_FormBuilder::field_groups_delete';

    public function __construct(Context $context, private readonly FieldGroupManager $manager)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create();
        try {
            $this->manager->delete((int)$this->getRequest()->getParam('id'));
            $this->messageManager->addSuccessMessage(__('The field group has been deleted. Forms that used it are not changed.'));
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $redirect->setPath('*/*/');
    }
}
