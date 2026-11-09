<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Url;

use Magento\Framework\App\Request\Http;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository;

/**
 * Storefront route and static asset URLs for code that renders outside a block (same scheme as the page).
 */
class StorefrontUrls
{
    public function __construct(
        private readonly UrlInterface $url,
        private readonly Http $request,
        private readonly Repository $assets
    ) {
    }

    /** @param array<string, scalar> $query */
    public function route(string $path, array $query = []): string
    {
        $params = ['_secure' => $this->request->isSecure(), '_nosid' => true, '_scope_to_url' => true];
        return $this->url->getUrl($path, $query === [] ? $params : $params + ['_query' => $query]);
    }

    public function asset(string $fileId): string
    {
        return $this->assets->getUrlWithParams($fileId, ['_secure' => $this->request->isSecure()]);
    }
}
