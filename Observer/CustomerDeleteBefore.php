<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Observer;

use Furan\FormBuilder\Model\Privacy\DeletedAccountQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/** Runs inside the customer delete transaction. A failure is rethrown so the account is not deleted unprocessed. */
class CustomerDeleteBefore implements ObserverInterface
{
    public function __construct(
        private readonly DeletedAccountQueue $queue,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $customer = $observer->getEvent()->getData('customer') ?? $observer->getEvent()->getData('data_object');
        $customerId = (int)($customer?->getId());
        if ($customerId <= 0) {
            return;
        }
        try {
            $this->queue->capture($customerId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Form Builder: could not read submissions of deleted customer ' . $customerId . ': ' . $e->getMessage(),
                ['exception' => $e]
            );
            throw $e;
        }
    }
}
