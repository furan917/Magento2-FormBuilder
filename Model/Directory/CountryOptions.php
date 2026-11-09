<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Directory;

use Magento\Directory\Model\ResourceModel\Country\CollectionFactory;

class CountryOptions
{
    public function __construct(private readonly CollectionFactory $collectionFactory)
    {
    }

    /** @return array<int, array{value: string, label: string}> */
    public function forStore(int $storeId): array
    {
        $options = $this->collectionFactory->create()->loadByStore($storeId)->toOptionArray(false);
        usort($options, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));
        return $options;
    }
}
