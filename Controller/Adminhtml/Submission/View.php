<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Model\ResourceModel\Submission as SubmissionResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\PageFactory;

class View extends Action implements HttpGetActionInterface
{
    use ReturnsRedirect;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::submissions';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly SubmissionRepositoryInterface $submissionRepository,
        private readonly SubmissionResource $resource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        try {
            $submission = $this->submissionRepository->getById($id);
            if (!$submission->isRead()) {
                $this->resource->markRead($id);
            }
        } catch (LocalizedException) {
            $this->messageManager->addErrorMessage(__('This submission no longer exists.'));
            return $this->redirectResult()->setPath('*/*/');
        }

        /** @var Page $page */
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Furan_FormBuilder::submission_list');
        $page->getConfig()->getTitle()->prepend((string)__('Submission #%1', $id));
        return $page;
    }
}
