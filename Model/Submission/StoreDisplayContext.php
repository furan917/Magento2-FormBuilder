<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Api\Data\DisplayContext;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Locale\Resolver;
use Magento\Store\Model\ScopeInterface;

/**
 * Display context of a store view, with its locale (general/locale/code) read once per store.
 */
class StoreDisplayContext
{
    private const PATH = 'general/locale/code';

    /** @var array<int, DisplayContext> */
    private array $contexts = [];

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    /** Null or 0 (the store view was deleted) reads the default scope. */
    public function forStore(?int $storeId): DisplayContext
    {
        $key = (int)$storeId;
        if (!isset($this->contexts[$key])) {
            $code = $key > 0
                ? $this->scopeConfig->getValue(self::PATH, ScopeInterface::SCOPE_STORE, $key)
                : $this->scopeConfig->getValue(self::PATH, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null);
            $locale = is_string($code) && $code !== '' ? $code : Resolver::DEFAULT_LOCALE;
            $this->contexts[$key] = DisplayContext::forStore($key, $locale);
        }
        return $this->contexts[$key];
    }
}
