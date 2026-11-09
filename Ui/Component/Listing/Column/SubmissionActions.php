<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Ui\Component\Listing\Column;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class SubmissionActions extends Column
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
        $canDelete = $this->authorization->isAllowed('Furan_FormBuilder::submissions_delete');
        foreach ($dataSource['data']['items'] ?? [] as $index => $item) {
            if (!isset($item['submission_id'])) {
                continue;
            }
            $params = ['id' => (int)$item['submission_id']];
            $actions = [
                'view' => [
                    'href' => $this->urlBuilder->getUrl('furanform/submission/view', $params),
                    'label' => __('View'),
                ],
            ];
            // Confirm text is rendered as HTML by the grid, so it carries no row data.
            if ($canDelete) {
                $actions['delete'] = [
                    'href' => $this->urlBuilder->getUrl('furanform/submission/delete', $params),
                    'label' => __('Delete'),
                    'post' => true,
                    'confirm' => [
                        'title' => __('Delete submission'),
                        'message' => __('Are you sure you want to delete this submission and its files?'),
                    ],
                ];
            }
            $dataSource['data']['items'][$index][$this->getData('name')] = $actions;
        }
        return $dataSource;
    }
}
