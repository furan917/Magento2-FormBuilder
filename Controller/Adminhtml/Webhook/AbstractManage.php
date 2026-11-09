<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Webhook;

use Furan\FormBuilder\Model\Webhook\WebhookAccess;
use Magento\Backend\App\Action;
use Magento\Framework\App\Request\Http;
use Magento\User\Model\User;

/**
 * Creating or changing an endpoint is a continuous export, so it needs the view, manage and export permissions.
 */
abstract class AbstractManage extends Action
{
    public const ADMIN_RESOURCE = 'Furan_FormBuilder::webhooks_manage';
    public const VIEW_RESOURCE = 'Furan_FormBuilder::webhooks';

    protected function _isAllowed()
    {
        return WebhookAccess::canChange($this->_authorization, static::ADMIN_RESOURCE);
    }

    protected function adminId(): ?int
    {
        /** @var User|null $user */
        $user = $this->_auth->getUser();

        return $user !== null ? (int)$user->getId() : null;
    }

    protected function httpRequest(): Http
    {
        /** @var Http $request */
        $request = $this->getRequest();

        return $request;
    }
}
