<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Form;

use Furan\FormBuilder\Api\FormWriteContext;
use Magento\Backend\App\Action;
use Magento\User\Model\User;

abstract class AbstractForm extends Action
{
    public const ADMIN_RESOURCE = 'Furan_FormBuilder::forms';

    protected function writeContext(): FormWriteContext
    {
        /** @var User|null $user */
        $user = $this->_auth->getUser();
        return FormWriteContext::forAdmin($this->_authorization, $user !== null ? (int)$user->getId() : null);
    }
}
