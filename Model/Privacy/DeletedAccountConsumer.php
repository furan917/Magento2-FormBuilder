<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Privacy;

use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/** Handler of furan_formbuilder.privacy. Never throws: failed rows stay pending for the retry and the cron sweep. */
class DeletedAccountConsumer
{
    public function __construct(
        private readonly PendingProcessor $processor,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(string $message): void
    {
        try {
            $customerId = (int)($this->json->unserialize($message)['customer_id'] ?? 0);
            if ($customerId > 0) {
                $this->processor->processCustomer($customerId);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Form Builder privacy queue: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
