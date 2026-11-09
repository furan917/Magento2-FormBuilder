<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * @phpstan-type ProductData array{id: int, sku: string, name: string, url: string, price: float, priceFormatted: string, currency: string}
 */
class PrefillService
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly Sources $sources
    ) {
    }

    /**
     * Customer-derived values keyed by source code ('' when the customer has no data); product sources are ignored.
     * Callers pass the customer id; this class never reads the session.
     *
     * @param string[] $sources
     * @return array<string, string|array<string, mixed>>
     */
    public function valuesFor(int $customerId, array $sources, int $storeId): array
    {
        $wanted = array_values(array_filter(
            array_unique($sources),
            fn ($code): bool => is_string($code) && $this->sources->isValid($code) && !$this->sources->isProduct($code)
        ));
        if ($customerId <= 0 || $wanted === []) {
            return [];
        }
        $context = $this->customerContext($customerId, $storeId);

        $values = [];
        foreach ($wanted as $code) {
            $values[$code] = $this->sources->get($code)?->resolve($context) ?? '';
        }
        return $values;
    }

    private function customerContext(int $customerId, int $storeId): PrefillContext
    {
        return new PrefillContext($storeId, null, fn (): ?CustomerInterface => $this->loadCustomer($customerId));
    }

    private function loadCustomer(int $customerId): ?CustomerInterface
    {
        try {
            return $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException | LocalizedException) {
            return null;
        }
    }

    /**
     * Group pricing (catalog rules, tier prices) comes implicitly from the customer session, so call this
     * only from uncached requests (token, submit).
     *
     * @return ProductData|null
     */
    public function product(int $productId, int $storeId): ?array
    {
        if ($productId <= 0) {
            return null;
        }
        try {
            $product = $this->productRepository->getById($productId, false, $storeId);
            $store = $this->storeManager->getStore($storeId);
        } catch (NoSuchEntityException) {
            return null;
        }
        /** @var Product $product */
        /** @var Store $store */
        if ((int)$product->getStatus() !== Status::STATUS_ENABLED
            || !in_array((int)$product->getVisibility(), [
                Visibility::VISIBILITY_IN_CATALOG,
                Visibility::VISIBILITY_IN_SEARCH,
                Visibility::VISIBILITY_BOTH,
            ], true)
            || !in_array((int)$store->getWebsiteId(), array_map('intval', (array)$product->getWebsiteIds()), true)
        ) {
            return null;
        }

        // Already in the store's current (display) currency; format() must not convert it again.
        $price = (float)$product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();

        return [
            'id' => (int)$product->getId(),
            'sku' => (string)$product->getSku(),
            'name' => (string)$product->getName(),
            'url' => (string)$product->getProductUrl(),
            'price' => $price,
            'priceFormatted' => (string)$this->priceCurrency->format(
                $price,
                false,
                PriceCurrencyInterface::DEFAULT_PRECISION,
                $store
            ),
            'currency' => (string)$store->getCurrentCurrencyCode(),
        ];
    }
}
