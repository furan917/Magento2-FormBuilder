<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Source;

use Furan\FormBuilder\Model\ResourceModel\Form\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

class AllForms implements OptionSourceInterface
{
    public function __construct(private readonly CollectionFactory $collectionFactory)
    {
    }

    /**
     * @return array<int, array{value: int|string, label: string|\Magento\Framework\Phrase}>
     */
    public function toOptionArray()
    {
        $options = [];
        $collection = $this->collectionFactory->create()->addFieldToSelect(['form_id', 'title']);
        foreach ($collection as $form) {
            $options[] = ['value' => (int)$form->getData('form_id'), 'label' => (string)$form->getData('title')];
        }
        return $options;
    }
}
