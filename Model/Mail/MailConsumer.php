<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Mail;

use Furan\FormBuilder\Model\Claim\ClaimMailer;
use Furan\FormBuilder\Model\Claim\ClaimService;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Email\StoreScope;
use Furan\FormBuilder\Model\Thread\QueuedCustomerReply;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Handler of the furan_formbuilder.mail queue (claim and customer reply emails). Never throws: a message that cannot be sent is
 * logged and acknowledged, so one bad message cannot block the queue or be retried forever.
 */
class MailConsumer
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly ClaimService $claimService,
        private readonly ClaimMailer $claimMailer,
        private readonly Config $config,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly StoreScope $storeScope,
        private readonly QueuedCustomerReply $replies
    ) {
    }

    public function process(string $message): void
    {
        try {
            $data = $this->json->unserialize($message);
            $id = (int)($data['id'] ?? 0);
            match ($data['kind'] ?? null) {
                MailQueue::KIND_CLAIM => $this->claim($id, (int)($data['store'] ?? 0)),
                MailQueue::KIND_REPLY => $this->replies->send($id),
                default => $this->logger->warning('Form Builder mail queue: unknown message dropped.'),
            };
        } catch (\Throwable $e) {
            $this->logger->error('Form Builder mail queue: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    private function claim(int $customerId, int $storeId): void
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException) {
            return;
        }
        $email = trim((string)$customer->getEmail());
        $storeId = $storeId ?: (int)$customer->getStoreId();
        // Re-checked: the customer may have claimed or been erased since the login that queued this.
        if (!$this->config->isClaimEnabled($storeId)
            || !$this->claimService->hasUnlinked($email, (int)$customer->getWebsiteId())
        ) {
            return;
        }
        $name = trim($customer->getFirstname() . ' ' . $customer->getLastname());
        $this->storeScope->run($storeId, fn () => $this->claimMailer->send($customerId, $email, $name, $storeId));
    }
}
