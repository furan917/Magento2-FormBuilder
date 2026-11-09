<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\FieldGroup;

use Furan\FormBuilder\Model\FieldGroup\FieldGroupManager;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Furan_FormBuilder::field_groups';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly FieldGroupManager $manager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $canEdit = $this->_authorization->isAllowed('Furan_FormBuilder::field_groups_edit');
        try {
            $name = (string)$this->manager->get((int)$this->getRequest()->getParam('id'))->getData('name');
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }
        /** @var \Magento\Backend\Model\View\Result\Page $page */
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Furan_FormBuilder::field_group_list');
        $page->getConfig()->getTitle()->prepend((string)($canEdit ? __('Edit "%1"', $name) : __('View "%1"', $name)));
        return $page;
    }
}
