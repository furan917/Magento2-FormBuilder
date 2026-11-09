<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Account;

use Furan\FormBuilder\Model\Claim\ClaimService;
use Furan\FormBuilder\Model\Claim\ClaimToken;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Customer\Impersonation;
use Furan\FormBuilder\Model\Submission\EmailHasher;
use Magento\Customer\Controller\AccountInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Redeems the emailed claim link. Idempotent: the signed token only proves the account mailbox
 * and the guarded update touches unlinked rows of that email.
 */
class Claim implements AccountInterface, HttpGetActionInterface
{
    public function __construct(
        private readonly RedirectFactory $redirectFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly Session $session,
        private readonly Http $request,
        private readonly ClaimToken $claimToken,
        private readonly ClaimService $claimService,
        private readonly EmailHasher $emailHasher,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger,
        private readonly Impersonation $impersonation
    ) {
    }

    /** @return Redirect|Forward */
    public function execute()
    {
        $customerId = (int)$this->session->getCustomerId();
        if ($customerId <= 0 || !$this->config->isClaimEnabled((int)$this->storeManager->getStore()->getId())) {
            return $this->forwardFactory->create()->forward('noroute');
        }

        if ($this->impersonation->isActive()) {
            $this->messageManager->addErrorMessage($this->impersonation->accountRefusal());
            return $this->toList();
        }
        try {
            $customer = $this->session->getCustomerData();
            $email = (string)$customer->getEmail();
            $token = $this->request->getParam('token');
            if (!is_string($token) || !$this->claimToken->verify($token, $customerId, $this->emailHasher->candidates($email))) {
                $this->messageManager->addErrorMessage((string)__('This link is invalid or has expired.'));
            } else {
                $linked = $this->claimService->claim($customerId, $email, (int)$customer->getWebsiteId());
                $this->messageManager->addSuccessMessage($linked > 0
                    ? (string)__('%1 submission(s) were added to your account.', $linked)
                    : (string)__('There were no submissions left to add to your account.'));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Furan_FormBuilder: claim failed: ' . $e::class);
            $this->messageManager->addErrorMessage((string)__('Your submissions could not be added. Please try again later.'));
        }

        return $this->toList();
    }

    /** The token is in this request's URL: keep it out of Referer headers and caches. */
    private function toList(): Redirect
    {
        return $this->redirectFactory->create()
            ->setHeader('Referrer-Policy', 'no-referrer', true)
            ->setHeader('Cache-Control', 'no-store, private', true)
            ->setPath('furanform/account/submissions');
    }
}
