<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Observer;

use Furan\FormBuilder\Model\Claim\ClaimMailer;
use Furan\FormBuilder\Model\Claim\ClaimService;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Mail\MailQueue;
use Furan\FormBuilder\Model\Submission\EmailHasher;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/** On customer login, emails a signed claim link when unlinked guest submissions exist; never breaks login. */
class OfferClaim implements ObserverInterface
{
    private const THROTTLE_SECONDS = 86400;

    public function __construct(
        private readonly Config $config,
        private readonly ClaimService $claimService,
        private readonly EmailHasher $emailHasher,
        private readonly CacheInterface $cache,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly ClaimMailer $claimMailer,
        private readonly MailQueue $mailQueue
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $customer = $observer->getEvent()->getData('customer');
            $customerId = (int)$customer->getId();
            $email = trim((string)$customer->getEmail());
            $storeId = (int)$customer->getStoreId() ?: (int)$this->storeManager->getStore()->getId();
            $cacheKey = 'ffb_claim_' . $customerId;
            if ($customerId < 1 || $this->emailHasher->hash($email) === null || !$this->config->isEnabled($storeId)
                || !$this->config->isClaimEnabled($storeId)
                || $this->cache->load($cacheKey)
                || !$this->claimService->hasUnlinked($email, (int)$customer->getWebsiteId())
            ) {
                return;
            }
            if (!$this->mailQueue->publishClaim($customerId, $storeId)) {
                $this->claimMailer->send($customerId, $email, (string)$customer->getName(), $storeId);
            }
            $this->cache->save('1', $cacheKey, [], self::THROTTLE_SECONDS);
        } catch (\Throwable $e) {
            $this->logger->error('Furan_FormBuilder: claim offer failed: ' . $e::class);
        }
    }
}
