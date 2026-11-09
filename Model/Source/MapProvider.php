<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class MapProvider implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: int|string, label: string|\Magento\Framework\Phrase}>
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'osm', 'label' => __('OpenStreetMap')],
            ['value' => 'google', 'label' => __('Google Maps')],
        ];
    }
}
