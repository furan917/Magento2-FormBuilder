<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block\Adminhtml\Form;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class AddButton implements ButtonProviderInterface
{
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /** @return array<string, mixed> */
    public function getButtonData()
    {
        if (!$this->authorization->isAllowed('Furan_FormBuilder::forms_save')) {
            return [];
        }
        return [
            'label' => __('Add New Form'),
            'class' => 'primary',
            'on_click' => sprintf("location.href = '%s';", $this->urlBuilder->getUrl('*/*/new')),
            'sort_order' => 10,
        ];
    }
}
