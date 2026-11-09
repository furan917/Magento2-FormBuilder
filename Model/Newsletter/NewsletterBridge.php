<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Newsletter;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The only class that names Magento_Newsletter. Its types appear as strings inside methods, never in a
 * constructor, di.xml, factory or proxy, so the module compiles and runs without the module installed.
 */
class NewsletterBridge
{
    private const MODULE = 'Magento_Newsletter';
    private const ACTIVE_PATH = 'newsletter/general/active';
    private const GUEST_PATH = 'newsletter/subscription/allow_guest_subscribe';
    private const MANAGER = 'Magento\\Newsletter\\Model\\SubscriptionManagerInterface';
    private const SUBSCRIBERS = 'Magento\\Newsletter\\Model\\SubscriberFactory';

    /** @param string[] $requiredClasses overrides the classes that must exist (empty = the built-in list) */
    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ObjectManagerInterface $objectManager,
        private readonly CustomerRepositoryInterface $customers,
        private readonly StoreManagerInterface $storeManager,
        private readonly array $requiredClasses = []
    ) {
    }

    /** Null = module level (palette, normaliser); a store id also needs newsletters switched on for that store. */
    public function isAvailable(?int $storeId = null): bool
    {
        if (!$this->moduleManager->isEnabled(self::MODULE) || !$this->classesExist()) {
            return false;
        }
        return $storeId === null || $this->scopeConfig->isSetFlag(self::ACTIVE_PATH, 'store', $storeId);
    }

    public function allowsGuest(int $storeId): bool
    {
        return $this->isAvailable($storeId) && $this->scopeConfig->isSetFlag(self::GUEST_PATH, 'store', $storeId);
    }

    /** The subscription manager applies the store's confirmation setting itself. */
    public function subscribeCustomer(int $customerId, int $storeId): bool
    {
        if (!$this->isAvailable($storeId)) {
            return false;
        }
        $this->service(self::MANAGER)->subscribeCustomer($customerId, $storeId);

        return true;
    }

    /**
     * Core re-subscribes any row that is not subscribed and mails every time, so the guest path stays out of
     * addresses that already have a row (respects unsubscribes) or an account on the website.
     */
    public function subscribeGuest(string $email, int $storeId): bool
    {
        if (!$this->allowsGuest($storeId) || $this->isKnownAddress($email, $storeId)) {
            return false;
        }
        $this->service(self::MANAGER)->subscribe($email, $storeId);

        return true;
    }

    private function isKnownAddress(string $email, int $storeId): bool
    {
        $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        if ($this->service(self::SUBSCRIBERS)->create()->loadBySubscriberEmail($email, $websiteId)->getId()) {
            return true;
        }
        try {
            $this->customers->get($email, $websiteId);
        } catch (NoSuchEntityException) {
            return false;
        }

        return true;
    }

    /** The only ObjectManager use: resolves a Newsletter type by name, and only while the module is available. */
    private function service(string $class): mixed
    {
        if (!$this->isAvailable()) {
            throw new \LogicException('Magento_Newsletter is not available');
        }

        return $this->objectManager->get($class);
    }

    private function classesExist(): bool
    {
        $classes = $this->requiredClasses ?: ['Magento\\Newsletter\\Model\\Subscriber'];
        foreach ($classes as $class) {
            if (!class_exists($class) && !interface_exists($class)) {
                return false;
            }
        }
        return true;
    }
}
