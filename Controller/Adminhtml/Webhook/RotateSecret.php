<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Webhook;

use Furan\FormBuilder\Model\Webhook\EndpointActions;
use Furan\FormBuilder\Model\Webhook\SecretStash;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class RotateSecret extends AbstractManage implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly EndpointActions $actions,
        private readonly SecretStash $stash
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->httpRequest();
        $id = (int)$request->getPost('id');
        $redirect = $this->resultRedirectFactory->create();
        try {
            $secret = $this->actions->rotate($id, (int)$request->getPost('window_hours'), $this->adminId());
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setPath('*/*/');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setPath('*/*/edit', ['id' => $id]);
        }
        $this->stash->put($secret->endpointId(), $secret->plain());
        $this->messageManager->addSuccessMessage(__('The signing secret was rolled. Copy the new secret now.'));

        return $redirect->setPath('*/*/edit', ['id' => $id]);
    }
}
