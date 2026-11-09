<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Config\Backend;

use Furan\FormBuilder\Model\Config;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\App\Route\ConfigInterface as RouteConfig;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * The router ignores a prefix outside Config::ROUTE_PREFIX_PATTERN, so such a value is refused here instead.
 */
class RoutePrefix extends Value
{
    // Cached pages and blocks hold links under the old prefix.
    private const CACHE_TYPES = ['full_page', 'block_html'];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly RouteConfig $routeConfig,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        if (preg_match(Config::ROUTE_PREFIX_PATTERN, $value) !== 1) {
            throw new LocalizedException(__(
                'The URL prefix must start with a lowercase letter or digit and use only lowercase letters, digits and hyphens, at most 32 characters.'
            ));
        }
        // A storefront route with this front name is matched first, so forms under it would never be reached.
        if ($this->routeConfig->getRouteByFrontName($value, 'frontend') !== false) {
            throw new LocalizedException(__('The URL prefix "%1" is already used by another storefront page. Choose another one.', $value));
        }
        $this->setValue($value);

        return parent::beforeSave();
    }

    public function afterSave()
    {
        if ($this->isValueChanged()) {
            $this->flushPages();
        }
        return parent::afterSave();
    }

    public function afterDelete()
    {
        $this->flushPages();
        return parent::afterDelete();
    }

    private function flushPages(): void
    {
        foreach (self::CACHE_TYPES as $type) {
            $this->cacheTypeList->cleanType($type);
            $this->_eventManager->dispatch('adminhtml_cache_refresh_type', ['type' => $type]);
        }
    }
}
