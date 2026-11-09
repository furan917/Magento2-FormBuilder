<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Source;

use Furan\FormBuilder\Model\ResourceModel\Form\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

class Form implements OptionSourceInterface
{
    public function __construct(private readonly CollectionFactory $collectionFactory)
    {
    }

    /**
     * @return array<int, array{value: int|string, label: string|\Magento\Framework\Phrase}>
     */
    public function toOptionArray()
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToSelect(['form_id', 'title', 'identifier'])
            // @phpstan-ignore argument.type (Magento accepts scalar conditions)
            ->addFieldToFilter('is_active', 1)->setOrder('title', 'ASC');

        $options = [];
        foreach ($collection as $form) {
            $options[] = [
                'value' => (int)$form->getData('form_id'),
                'label' => $form->getData('title') . ' (' . $form->getData('identifier') . ')',
            ];
        }
        return $options;
    }
}
