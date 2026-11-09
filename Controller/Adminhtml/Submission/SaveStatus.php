<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Model\Source\SubmissionStatus;
use Furan\FormBuilder\Model\Submission\StatusWriter;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;

class SaveStatus extends Action implements HttpPostActionInterface
{
    use ReturnsRedirect;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::submissions_reply';

    public function __construct(
        Context $context,
        private readonly SubmissionStatus $statuses,
        private readonly StatusWriter $writer
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $status = $this->statuses->parse($this->getRequest()->getParam('status'));
        $redirect = $this->redirectResult();
        if ($status === null) {
            $this->messageManager->addErrorMessage(__('Please select a valid status.'));
            return $redirect->setPath('*/*/view', ['id' => $id]);
        }
        if ($this->writer->change([$id], $status, 'admin') === 0) {
            $this->messageManager->addErrorMessage(__('This submission no longer exists.'));
            return $redirect->setPath('*/*/');
        }
        $this->messageManager->addSuccessMessage(__('The status has been updated.'));

        return $redirect->setPath('*/*/view', ['id' => $id]);
    }
}
