<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Config\Backend;

use Furan\FormBuilder\Model\Http\SafeHttpClient;
use Furan\FormBuilder\Model\Http\UrlNormalizer;
use Furan\FormBuilder\Model\Map\MapRequestPolicy;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

class SearchEndpoint extends Value
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly UrlNormalizer $urlNormalizer,
        private readonly SafeHttpClient $httpClient,
        private readonly MapRequestPolicy $requestPolicy,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        if ($value !== '') {
            $policy = $this->requestPolicy->build();
            $url = $this->urlNormalizer->normalize($value, $policy);
            if ($this->httpClient->preflight($url, $policy) !== SafeHttpClient::PREFLIGHT_OK) {
                throw new LocalizedException(
                    __('The search endpoint host must resolve to a public internet address, not an internal one.')
                );
            }
            $value = $url->toString();
        }
        $this->setValue($value);

        return parent::beforeSave();
    }
}
