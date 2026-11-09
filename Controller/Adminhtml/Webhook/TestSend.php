<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Webhook;

use Furan\FormBuilder\Model\Webhook\EndpointActions;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class TestSend extends AbstractManage implements HttpPostActionInterface
{
    public function __construct(Context $context, private readonly EndpointActions $actions)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->httpRequest()->getPost('id');
        $adminId = $this->adminId();
        $redirect = $this->resultRedirectFactory->create();
        try {
            if ($adminId === null) {
                throw new LocalizedException(__('Your session has expired.'));
            }
            $this->actions->sendTest($id, $adminId);
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setPath('*/*/');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setPath('*/*/edit', ['id' => $id]);
        }
        $this->messageManager->addSuccessMessage(
            __('A test event was queued. Its result appears in the delivery log shortly.')
        );

        return $redirect->setPath('*/*/edit', ['id' => $id]);
    }
}
