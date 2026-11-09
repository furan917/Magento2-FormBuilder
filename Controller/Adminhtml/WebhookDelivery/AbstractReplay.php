<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\WebhookDelivery;

use Furan\FormBuilder\Model\Webhook\DeliveryReplay;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\User;

/** Replaying sends current data to a receiver, so it needs the replay permission on top of viewing the log. */
abstract class AbstractReplay extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Furan_FormBuilder::webhooks_replay';
    public const VIEW_RESOURCE = 'Furan_FormBuilder::webhooks';
    public const PERSONAL_RESOURCE = 'Furan_FormBuilder::webhooks_personal';

    public function __construct(Context $context, private readonly DeliveryReplay $replay)
    {
        parent::__construct($context);
    }

    /** @return bool true = Resend with a new webhook id, false = Redeliver with the original one */
    abstract protected function newId(): bool;

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(static::ADMIN_RESOURCE)
            && $this->_authorization->isAllowed(self::VIEW_RESOURCE);
    }

    public function execute()
    {
        /** @var Http $request */
        $request = $this->getRequest();
        $deliveryId = (int)$request->getPost('delivery_id');
        $redirect = $this->resultRedirectFactory->create();
        /** @var User|null $user */
        $user = $this->_auth->getUser();
        try {
            if ($user === null) {
                throw new LocalizedException(__('Your session has expired.'));
            }
            $newId = $this->replay->replay(
                $deliveryId,
                (int)$user->getId(),
                $this->newId(),
                $this->_authorization->isAllowed(self::PERSONAL_RESOURCE)
            );
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setPath('*/webhook/');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setPath('*/*/view', ['id' => $deliveryId]);
        }
        $this->messageManager->addSuccessMessage(
            __('The delivery was queued as a new row. It sends the submission\'s current data.')
        );

        return $redirect->setPath('*/*/view', ['id' => $newId]);
    }
}
