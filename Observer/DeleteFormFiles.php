<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Observer;

use Furan\FormBuilder\Model\Upload\FileStorage;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class DeleteFormFiles implements ObserverInterface
{
    public function __construct(
        private readonly FileStorage $fileStorage,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $form = $observer->getEvent()->getData('data_object');
        if ($form === null) {
            return;
        }
        try {
            $this->fileStorage->deleteFormFiles((int)$form->getId());
        } catch (\Throwable $e) {
            $this->logger->error('Form Builder: could not delete form uploads: ' . $e->getMessage());
        }
    }
}
