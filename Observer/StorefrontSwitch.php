<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Observer;

use Furan\FormBuilder\Model\Config;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NotFoundException;
use Magento\Store\Model\StoreManagerInterface;

/** Every furanform storefront route (pages, submit, token, uploads, drafts, account) 404s while forms are disabled. */
class StorefrontSwitch implements ObserverInterface
{
    private const MODULE = 'furanform';

    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function execute(Observer $observer): void
    {
        $request = $observer->getEvent()->getData('request');
        $this->assertOpen($request instanceof RequestInterface ? $request : null);
    }

    /** @throws NotFoundException */
    public function assertOpen(?RequestInterface $request): void
    {
        // The 404 forward keeps the stale route name, so only act on requests still addressed to this module.
        if ($request?->getModuleName() !== self::MODULE) {
            return;
        }
        if (!$this->config->isEnabled((int)$this->storeManager->getStore()->getId())) {
            throw new NotFoundException(__('The requested form was not found.'));
        }
    }
}
