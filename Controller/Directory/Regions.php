<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Directory;

use Magento\Directory\Model\AllowedCountries;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Regions implements HttpGetActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly AllowedCountries $allowedCountries,
        private readonly CollectionFactory $regionCollectionFactory
    ) {
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $country = $this->request->getParam('country');
        if (!is_string($country) || !preg_match('/^[A-Z]{2}$/', $country) || !$this->isAllowed($country)) {
            $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private', true);
            $result->setHeader('Pragma', 'no-cache', true);
            return $result->setHttpResponseCode(404)->setData([]);
        }

        $regions = [];
        foreach ($this->regionCollectionFactory->create()->addCountryFilter($country) as $region) {
            $regions[] = [
                'id' => (string)$region->getRegionId(),
                'code' => (string)$region->getCode(),
                'name' => (string)$region->getName(),
            ];
        }
        $result->setHeader('Cache-Control', 'public, max-age=86400, s-maxage=86400', true);
        return $result->setData($regions);
    }

    private function isAllowed(string $country): bool
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        // @phpstan-ignore argument.type (Magento resolves an int store id here, as its own callers pass it)
        $allowed = $this->allowedCountries->getAllowedCountries(ScopeInterface::SCOPE_STORE, $storeId);
        return in_array($country, array_map('strval', array_keys($allowed)), true);
    }
}
