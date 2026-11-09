<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Seo;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\ResourceModel\Form\CollectionFactory;
use Magento\Sitemap\Model\ItemProvider\ConfigReaderInterface;
use Magento\Sitemap\Model\ItemProvider\ItemProviderInterface;
use Magento\Sitemap\Model\SitemapItemInterfaceFactory;

/**
 * Adds active, indexable forms to sitemap.xml with the same priority and frequency as CMS pages.
 * Only built when Magento_Sitemap is enabled (see etc/di.xml); the module does not require it.
 */
class SitemapItems implements ItemProviderInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly FormUrls $formUrls,
        private readonly ConfigReaderInterface $configReader,
        private readonly SitemapItemInterfaceFactory $itemFactory,
        private readonly Config $config
    ) {
    }

    public function getItems($storeId)
    {
        $storeId = (int)$storeId;
        if (!$this->config->isEnabled($storeId)) {
            return [];
        }
        $collection = $this->collectionFactory->create()
            ->addFieldToSelect(['form_id', 'identifier', 'settings_json', 'updated_at'])
            // @phpstan-ignore argument.type (Magento accepts scalar conditions)
            ->addFieldToFilter('is_active', 1)
            ->addStoreFilter($storeId);
        $items = [];
        foreach ($collection as $form) {
            if (!empty($form->getSettings()['seo_noindex'])) {
                continue;
            }
            $items[] = $this->itemFactory->create([
                'url' => $this->formUrls->path($form, $storeId),
                'updatedAt' => $form->getUpdatedAt(),
                'priority' => $this->configReader->getPriority($storeId),
                'changeFrequency' => $this->configReader->getChangeFrequency($storeId),
            ]);
        }
        return $items;
    }
}
