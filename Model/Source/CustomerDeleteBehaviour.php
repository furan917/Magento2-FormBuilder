<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class CustomerDeleteBehaviour implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: int|string, label: string|\Magento\Framework\Phrase}>
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'anonymise', 'label' => __('Anonymise submissions')],
            ['value' => 'keep', 'label' => __('Keep submissions unlinked')],
            ['value' => 'delete', 'label' => __('Delete submissions')],
        ];
    }
}
