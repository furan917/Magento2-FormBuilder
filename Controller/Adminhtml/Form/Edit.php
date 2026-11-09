<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Form;

use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;

class Edit extends AbstractForm implements HttpGetActionInterface
{
    use ReturnsRedirect;

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly FormRepositoryInterface $formRepository
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $canSave = $this->_authorization->isAllowed('Furan_FormBuilder::forms_save');
        if ($id <= 0 && !$canSave) {
            return $this->redirectResult()->setPath('*/*/');
        }
        $title = __('New Form');
        if ($id > 0) {
            try {
                $formTitle = $this->formRepository->getById($id)->getTitle();
                $title = $canSave ? __('Edit Form: %1', $formTitle) : __('View Form: %1', $formTitle);
            } catch (NoSuchEntityException) {
                $this->messageManager->addErrorMessage(__('This form no longer exists.'));
                return $this->redirectResult()->setPath('*/*/');
            }
        }
        /** @var \Magento\Backend\Model\View\Result\Page $page */
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Furan_FormBuilder::form_list');
        $page->getConfig()->getTitle()->prepend((string)$title);
        return $page;
    }
}
