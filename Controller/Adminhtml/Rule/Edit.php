<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Rule;

use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Model\Validation\SavedRuleManager;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;

class Edit extends Action implements HttpGetActionInterface
{
    use ReturnsRedirect;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::rules';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly SavedRuleManager $manager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $canSave = $this->_authorization->isAllowed('Furan_FormBuilder::rules_save');
        if ($id <= 0 && !$canSave) {
            return $this->redirectResult()->setPath('*/*/');
        }
        $title = __('New validation rule');
        if ($id > 0) {
            try {
                $label = $this->manager->get($id)->getData('label');
                $title = $canSave ? __('Edit "%1"', $label) : __('View "%1"', $label);
            } catch (NoSuchEntityException $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
                return $this->redirectResult()->setPath('*/*/');
            }
        }
        /** @var \Magento\Backend\Model\View\Result\Page $page */
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Furan_FormBuilder::rule_list');
        $page->getConfig()->getTitle()->prepend((string)$title);
        return $page;
    }
}
