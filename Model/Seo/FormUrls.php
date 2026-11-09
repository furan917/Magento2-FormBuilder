<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Seo;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Where a form lives on each store view, for canonical and hreflang links.
 */
class FormUrls
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UrlFactory $urlFactory
    ) {
    }

    public function path(FormInterface $form, int $storeId): string
    {
        return $this->pathFor($form->getIdentifier(), $storeId);
    }

    private function pathFor(string $identifier, int $storeId): string
    {
        return $this->config->getRoutePrefix($storeId) . '/' . $identifier;
    }

    public function url(FormInterface $form, int $storeId): string
    {
        /** @var Store $store */
        $store = $this->storeManager->getStore($storeId);
        return $store->getBaseUrl(UrlInterface::URL_TYPE_LINK) . $this->path($form, $storeId);
    }

    /**
     * Where a visitor opens the form on that store view: carries the store when the view is chosen per request.
     * Not for canonical or hreflang links, which stay without a store query.
     */
    public function storeUrl(FormInterface $form, int $storeId): string
    {
        return $this->storeUrlFor($form->getIdentifier(), $storeId);
    }

    public function storeUrlFor(string $identifier, int $storeId): string
    {
        // A fresh frontend Url: setScope() on the shared one would leak into later URLs of the request.
        $url = $this->urlFactory->create();
        $url->setScope($this->storeManager->getStore($storeId));
        return $url->getUrl('', ['_direct' => $this->pathFor($identifier, $storeId), '_scope_to_url' => true, '_nosid' => true]);
    }

    /**
     * hreflang => URL for every active store view showing the form, plus x-default. Empty when the store
     * views share one URL (e.g. switched by cookie), since alternates must point at distinct pages.
     *
     * @return array<string, string>
     */
    public function alternates(FormInterface $form): array
    {
        $assigned = $form->getStoreIds();
        $byUrl = [];
        /** @var Store[] $stores */
        $stores = $this->storeManager->getStores();
        foreach ($stores as $store) {
            if (!$store->isActive() || (!in_array(0, $assigned, true) && !in_array((int)$store->getId(), $assigned, true))) {
                continue;
            }
            $byUrl[$this->url($form, (int)$store->getId())] ??= $store;
        }
        if (count($byUrl) < 2) {
            return [];
        }
        $links = [];
        foreach ($byUrl as $url => $store) {
            $links[$this->hreflang($store)] ??= $url;
        }
        $links['x-default'] = $this->defaultUrl($form, $byUrl);
        return count($links) > 2 ? $links : [];
    }

    private function hreflang(Store $store): string
    {
        $locale = (string)$this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $store->getId());
        return strtolower(str_replace('_', '-', $locale !== '' ? $locale : 'en_US'));
    }

    /** @param array<string, Store> $byUrl */
    private function defaultUrl(FormInterface $form, array $byUrl): string
    {
        $default = $this->storeManager->getDefaultStoreView();
        foreach ($byUrl as $url => $store) {
            if ($default !== null && (int)$store->getId() === (int)$default->getId()) {
                return $url;
            }
        }
        return (string)array_key_first($byUrl);
    }
}
