<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Observer;

use Furan\FormBuilder\Model\Privacy\DeletedAccountQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/** Never throws: core rolls back after a throw here, reporting a delete that has already committed as failed. */
class CustomerDeleteCommitAfter implements ObserverInterface
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
            $this->queue->publish($customerId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Form Builder: could not wake the privacy queue for deleted customer ' . $customerId
                . ', the cron sweep processes its submissions: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
