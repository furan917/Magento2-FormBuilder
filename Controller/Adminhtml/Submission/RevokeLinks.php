<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Submission;

use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Model\Thread\ThreadAccess;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;

/** Ends every guest reply link and open guest session for one submission; the next admin reply mails a new link. */
class RevokeLinks extends Action implements HttpPostActionInterface
{
    use ReturnsRedirect;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::submissions_reply';

    public function __construct(
        Context $context,
        private readonly ThreadAccess $threadAccess
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $redirect = $this->redirectResult();
        if ($this->threadAccess->revoke([$id]) < 1) {
            $this->messageManager->addErrorMessage(__('This submission no longer exists.'));
            return $redirect->setPath('*/*/');
        }
        $this->messageManager->addSuccessMessage(__('Reply links sent for this submission no longer work.'));
        return $redirect->setPath('*/*/view', ['id' => $id]);
    }
}
