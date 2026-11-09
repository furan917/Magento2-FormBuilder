<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Account;

use Furan\FormBuilder\Model\Url\RedirectValidator;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Url\EncoderInterface;

class Login implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly Session $customerSession,
        private readonly RedirectValidator $redirectValidator,
        private readonly EncoderInterface $urlEncoder
    ) {
    }

    public function execute(): Redirect
    {
        $redirect = $this->redirectFactory->create();
        $return = $this->returnUrl();
        if ($return !== null && $this->customerSession->isLoggedIn()) {
            return $redirect->setUrl($return);
        }

        $params = ['_scope_to_url' => true];
        if ($return !== null) {
            $this->customerSession->setBeforeAuthUrl($return);
            $params['referer'] = $this->urlEncoder->encode($return);
        }
        $register = $this->request->getParam('register') === '1';

        return $redirect->setPath($register ? 'customer/account/create' : 'customer/account/login', $params);
    }

    /** Plain (already decoded) query value; must be an absolute http(s) URL on this store. */
    private function returnUrl(): ?string
    {
        $url = $this->request->getParam('return');
        if (!is_string($url) || preg_match('#^https?://#i', $url) !== 1 || !$this->redirectValidator->isAllowed($url)) {
            return null;
        }

        return $url;
    }
}
