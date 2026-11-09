<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Plugin\Admin;

use Furan\FormBuilder\Controller\Adminhtml\Submission\Open;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Backend\Model\Session;
use Magento\Backend\Model\Url;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\RequestInterface;

/**
 * With secret keys on, core sends every admin to the startup page after login. A submission link opened while
 * logged out is remembered here and becomes that startup page once, so the admin lands on the submission.
 */
class SubmissionLinkLogin
{
    private const KEY = 'furan_formbuilder_open_submission';

    public function __construct(
        private readonly AuthSession $authSession,
        private readonly Session $session,
        private readonly UrlInterface $backendUrl
    ) {
    }

    public function beforeDispatch(Open $subject, RequestInterface $request): void
    {
        $id = $request->getParam('id');
        // Without secret keys core returns to the requested URL itself and never asks for the startup page.
        if ($this->backendUrl->useSecretKey()
            && !$this->authSession->isLoggedIn()
            && is_string($id) && ctype_digit($id) && (int)$id > 0
        ) {
            // Session data goes through SessionManager::__call to its storage.
            // @phpstan-ignore method.notFound
            $this->session->setData(self::KEY, (int)$id);
        }
    }

    public function afterGetStartupPageUrl(Url $subject, string $result): string
    {
        if (!$this->authSession->isLoggedIn()) {
            return $result;
        }
        $id = $this->session->getData(self::KEY, true);
        return is_int($id) && $id > 0 ? 'furanform/submission/view/id/' . $id : $result;
    }
}
