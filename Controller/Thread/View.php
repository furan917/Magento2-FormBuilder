<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Thread;

use Furan\FormBuilder\Model\Thread\CurrentThread;
use Furan\FormBuilder\Model\Thread\PrivateResponse;
use Furan\FormBuilder\Model\Thread\ThreadAccess;
use Furan\FormBuilder\Model\Thread\ThreadGrants;
use Furan\FormBuilder\Model\Thread\ThreadLimits;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;

/** ?t= is swapped for a session grant and redirected away (never rendered); every failure is the same 404 page. */
class View implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly Http $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly ThreadAccess $threadAccess,
        private readonly ThreadGrants $grants,
        private readonly ThreadLimits $limits,
        private readonly Session $customerSession,
        private readonly CurrentThread $currentThread
    ) {
    }

    /** @return Page|Redirect */
    public function execute()
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        $token = $this->request->getParam('t');
        if (is_string($token) && $token !== '') {
            $context = $this->limits->allowTokenAttempt() ? $this->threadAccess->redeem($token, $storeId) : null;
            $params = ['_scope_to_url' => true];
            if ($context !== null) {
                $id = (int)$context['submission']->getId();
                $this->grants->grant($id, $context['version'], (string)end($context['fingerprints']));
                $params['_query'] = ['id' => $id];
            }
            return PrivateResponse::apply($this->redirectFactory->create()->setPath('furanform/thread/view', $params));
        }

        $id = $this->requestedId();
        $context = $this->threadAccess->load($id, $storeId);
        if ($context === null || !$this->grants->allows($id, $context['version'], $context['fingerprints'])) {
            return PrivateResponse::apply($this->page()->setHttpResponseCode(404));
        }
        $ownerId = (int)$context['submission']->getCustomerId();
        if ($ownerId > 0 && $ownerId === (int)$this->customerSession->getCustomerId()) {
            return PrivateResponse::apply(
                $this->redirectFactory->create()->setPath('furanform/account/submission', ['_query' => ['id' => $id], '_scope_to_url' => true])
            );
        }
        $this->currentThread->set($context);

        return PrivateResponse::apply($this->page());
    }

    private function page(): Page
    {
        $page = $this->pageFactory->create();
        $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');
        return $page;
    }

    /** Digits only: "12abc" or id[]= must not resolve to a submission. */
    private function requestedId(): int
    {
        $raw = $this->request->getParam('id');
        return is_string($raw) && ctype_digit($raw) && strlen($raw) <= 10 ? (int)$raw : 0;
    }
}
