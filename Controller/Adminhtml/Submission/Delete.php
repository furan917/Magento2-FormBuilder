<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;

class Delete extends Action implements HttpPostActionInterface
{
    use ReturnsRedirect;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::submissions_delete';

    public function __construct(Context $context, private readonly SubmissionRepositoryInterface $submissionRepository)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $submission = $this->submissionRepository->getById((int)$this->getRequest()->getParam('id'));
            $this->submissionRepository->delete($submission);
            $this->messageManager->addSuccessMessage(__('The submission has been deleted.'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $this->redirectResult()->setPath('*/*/');
    }
}
