<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;

/**
 * For admin controllers: ResultFactory::create() is typed as the generic ResultInterface.
 */
trait ReturnsRedirect
{
    private function redirectResult(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $redirect;
    }
}
