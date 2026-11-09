<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Webhook;

use Furan\FormBuilder\Model\Webhook\EndpointRepository;
use Furan\FormBuilder\Model\Webhook\WebhookAccess;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Furan_FormBuilder::webhooks';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly EndpointRepository $repository
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $title = (string)__('New webhook endpoint');
        if ($id <= 0 && !WebhookAccess::canChange($this->_authorization)) {
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }
        if ($id > 0) {
            try {
                $title = (string)__('Webhook endpoint "%1"', $this->repository->getView($id)->name());
            } catch (NoSuchEntityException $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
                return $this->resultRedirectFactory->create()->setPath('*/*/');
            }
        }
        /** @var \Magento\Backend\Model\View\Result\Page $page */
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Furan_FormBuilder::webhook_list');
        $page->getConfig()->getTitle()->prepend($title);
        // The page may show a new secret once, so no browser or proxy may keep it.
        $page->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $page->setHeader('Pragma', 'no-cache', true);
        return $page;
    }
}
