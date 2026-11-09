<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Plugin\Csp;

use Furan\FormBuilder\Model\Csp\MapMarker;
use Furan\FormBuilder\Model\Csp\MapPolicies;
use Magento\Csp\Api\CspRendererInterface;
use Magento\Csp\Model\Collector\DynamicCollector;
use Magento\Framework\App\Response\Http;
use Magento\Framework\App\Response\HttpInterface;
use Magento\Store\Model\StoreManagerInterface;

/** Reads the sent body: full page cache hits replay HTML without rendering blocks, so DynamicCollector stays empty. */
class MapPagePolicies
{
    public function __construct(
        private readonly MapPolicies $policies,
        private readonly DynamicCollector $collector,
        private readonly StoreManagerInterface $storeManager,
        private readonly MapMarker $marker
    ) {
    }

    public function beforeRender(CspRendererInterface $subject, HttpInterface $response): void
    {
        if (!$response instanceof Http) {
            return;
        }
        $body = (string)$response->getContent();
        if (!str_contains($body, MapMarker::ATTRIBUTE)) {
            return;
        }
        $storeId = (int)$this->storeManager->getStore()->getId();
        foreach ($this->marker->attributes($storeId) as $attribute) {
            if (str_contains($body, $attribute)) {
                foreach ($this->policies->forStore($storeId) as $policy) {
                    $this->collector->add($policy);
                }
                return;
            }
        }
    }
}
