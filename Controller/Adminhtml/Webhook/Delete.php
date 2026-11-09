<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Webhook;

use Furan\FormBuilder\Model\Webhook\EndpointAdmin;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class Delete extends AbstractManage implements HttpPostActionInterface
{
    public function __construct(Context $context, private readonly EndpointAdmin $admin)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create();
        try {
            $this->admin->delete((int)$this->httpRequest()->getPost('id'), $this->adminId());
            $this->messageManager->addSuccessMessage(__('The webhook endpoint has been deleted.'));
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $redirect->setPath('*/*/');
    }
}
