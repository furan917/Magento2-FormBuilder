<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Admin;

use Furan\FormBuilder\Model\Form\PreviewToken;
use Furan\FormBuilder\Model\Seo\FormUrls;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Where the builder's Preview opens a form: its public URL when active, a signed preview URL otherwise.
 */
class PreviewLink
{
    public function __construct(
        private readonly FormUrls $formUrls,
        private readonly PreviewToken $tokens,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlFactory $urlFactory
    ) {
    }

    /** @throws NoSuchEntityException when the store view is gone */
    public function url(int $formId, string $identifier, bool $active, int $storeId): ?string
    {
        if ($active) {
            return $this->formUrls->storeUrlFor($identifier, $storeId);
        }
        try {
            $token = $this->tokens->issue($formId);
        } catch (\RuntimeException) {
            return null;
        }
        // A fresh frontend Url: setScope() on the shared one would leak into later URLs of the request.
        $url = $this->urlFactory->create();
        $url->setScope($this->storeManager->getStore($storeId));
        return $url->getUrl('furanform/form/preview', [
            '_query' => ['id' => $formId, 'token' => $token],
            '_scope_to_url' => true,
            '_nosid' => true,
        ]);
    }
}
