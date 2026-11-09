<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Ui\Component\Listing\Column;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class FormActions extends Column
{
    /**
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly AuthorizationInterface $authorization,
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
        $canSave = $this->authorization->isAllowed('Furan_FormBuilder::forms_save');
        $canDelete = $this->authorization->isAllowed('Furan_FormBuilder::forms_delete');
        $canViewSubmissions = $this->authorization->isAllowed('Furan_FormBuilder::submissions');
        $canViewAnalytics = $this->authorization->isAllowed('Furan_FormBuilder::analytics');
        foreach ($dataSource['data']['items'] ?? [] as $index => $item) {
            if (!isset($item['form_id'])) {
                continue;
            }
            $id = (int)$item['form_id'];
            $params = ['id' => $id];
            $actions = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl('furanform/form/edit', $params),
                    'label' => $canSave ? __('Edit') : __('View'),
                ],
            ];
            if ($canViewSubmissions) {
                $actions['submissions'] = [
                    'href' => $this->urlBuilder->getUrl('furanform/submission/index', ['form_id' => $id]),
                    'label' => __('View submissions'),
                ];
            }
            if ($canViewAnalytics) {
                $actions['analytics'] = [
                    'href' => $this->urlBuilder->getUrl('furanform/analytics/index', ['form_id' => $id]),
                    'label' => __('Analytics'),
                ];
            }
            // The grid renders confirm text as HTML, so it carries no row data.
            if ($canSave) {
                $actions['duplicate'] = [
                    'href' => $this->urlBuilder->getUrl('furanform/form/duplicate', $params),
                    'label' => __('Duplicate'),
                    'post' => true,
                    'confirm' => [
                        'title' => __('Duplicate form'),
                        'message' => __('Create a disabled copy of this form?'),
                    ],
                ];
            }
            if ($canDelete) {
                $actions['delete'] = [
                    'href' => $this->urlBuilder->getUrl('furanform/form/delete', $params),
                    'label' => __('Delete'),
                    'post' => true,
                    'confirm' => [
                        'title' => __('Delete form'),
                        'message' => __('Are you sure you want to delete this form and its submissions?'),
                    ],
                ];
            }
            $dataSource['data']['items'][$index][$this->getData('name')] = $actions;
        }
        return $dataSource;
    }
}
