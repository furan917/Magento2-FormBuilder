<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Url;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

class RedirectValidator
{
    public function __construct(private readonly StoreManagerInterface $storeManager)
    {
    }

    public function isAllowed(string $url): bool
    {
        if ($url === '') {
            return true;
        }
        // whitespace, control chars, backslashes and encoded control chars (CR/LF smuggling)
        if (preg_match('/[\x00-\x20\x7F\\\\]|%(?:[01][0-9a-f]|7f)/i', $url) === 1) {
            return false;
        }
        if ($url[0] === '/') {
            return !str_starts_with($url, '//');
        }

        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $origin = $this->origin($parts);

        return $origin !== null && in_array($origin, $this->getAllowedOrigins(), true);
    }

    /**
     * scheme://host:port with the default port made explicit; null for anything but http(s).
     * @param array<string, mixed>|false $parts
     */
    private function origin(array|false $parts): ?string
    {
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }
        $scheme = strtolower((string)$parts['scheme']);
        $defaults = ['http' => 80, 'https' => 443];
        if (!isset($defaults[$scheme])) {
            return null;
        }

        return $scheme . '://' . strtolower((string)$parts['host']) . ':' . (int)($parts['port'] ?? $defaults[$scheme]);
    }

    /**
     * @return string[]
     */
    private function getAllowedOrigins(): array
    {
        $origins = [];
        /** @var Store[] $stores */
        $stores = $this->storeManager->getStores();
        foreach ($stores as $store) {
            foreach ([false, true] as $secure) {
                $origin = $this->origin(parse_url((string)$store->getBaseUrl(UrlInterface::URL_TYPE_WEB, $secure)));
                if ($origin !== null) {
                    $origins[$origin] = true;
                }
            }
        }
        return array_keys($origins);
    }
}
