<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Ui\Component\Listing\Columns\Column;

/** Store view name, with the website and store path as its title; the cell is rendered as HTML. */
class StoreView extends Column
{
    /**
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Escaper $escaper,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource)
    {
        $items = $dataSource['data']['items'] ?? [];
        if ($items === []) {
            return $dataSource;
        }
        $name = (string)$this->getData('name');
        $websites = $this->names($this->storeManager->getWebsites(true));
        $groups = $this->names($this->storeManager->getGroups(true));
        $cells = [];
        foreach ($this->storeManager->getStores(true) as $store) {
            $path = implode(' / ', [
                $websites[(int)$store->getWebsiteId()] ?? '',
                $groups[(int)$store->getStoreGroupId()] ?? '',
                (string)$store->getName(),
            ]);
            $cells[(int)$store->getId()] = '<span title="' . $this->escaper->escapeHtmlAttr($path) . '">'
                . $this->escaper->escapeHtml((string)$store->getName()) . '</span>';
        }
        foreach ($items as $index => $item) {
            $id = $item[$name] ?? null;
            $dataSource['data']['items'][$index][$name] = is_numeric($id) ? $cells[(int)$id] ?? '' : '';
        }
        return $dataSource;
    }

    /**
     * @param array<int|string, \Magento\Store\Api\Data\WebsiteInterface|\Magento\Store\Api\Data\GroupInterface> $entities
     * @return array<int, string>
     */
    private function names(array $entities): array
    {
        $names = [];
        foreach ($entities as $entity) {
            $names[(int)$entity->getId()] = (string)$entity->getName();
        }
        return $names;
    }
}
