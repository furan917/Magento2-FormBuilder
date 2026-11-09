<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block\Adminhtml\Submission;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class ExportButton implements ButtonProviderInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly AuthorizationInterface $authorization,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /** @return array<string, mixed> */
    public function getButtonData()
    {
        $formId = (int)$this->request->getParam('form_id');
        if ($formId <= 0 || !$this->authorization->isAllowed('Furan_FormBuilder::submissions_export')) {
            return [];
        }
        return [
            'label' => __('Export CSV'),
            'class' => 'action-secondary',
            'on_click' => sprintf("location.href = '%s';", $this->urlBuilder->getUrl('*/*/export', ['form_id' => $formId])),
            'sort_order' => 10,
        ];
    }
}
