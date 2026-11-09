<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Account;

use Furan\FormBuilder\Controller\FormKeyCsrf;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\Redirect;

/**
 * Account POST actions: XHR callers get a JSON 403, others go back to the submissions list.
 */
trait AccountCsrf
{
    use FormKeyCsrf;

    private function csrfRefusal(string $message): Json|Redirect
    {
        if ($this->request->isXmlHttpRequest()) {
            return $this->jsonFactory->create()
                ->setHttpResponseCode(403)
                ->setData(['success' => false, 'message' => $message]);
        }
        $this->messageManager->addErrorMessage($message);
        return $this->redirectFactory->create()->setPath('furanform/account/submissions');
    }
}
