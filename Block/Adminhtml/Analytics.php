<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block\Adminhtml;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Schema\SchemaFields;
use Furan\FormBuilder\Model\Stats\AnalyticsNotices;
use Furan\FormBuilder\Model\Stats\FreeTextReader;
use Furan\FormBuilder\Model\Stats\Query;
use Furan\FormBuilder\Model\Stats\RebuildQueue;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Model\Customer\Source\GroupSourceInterface;
use Magento\Framework\Serialize\Serializer\JsonHexTag;

/**
 * @phpstan-type Charts array{from: string, to: string, granularity: string, submissions: int, time: list<array{period: string, submissions: int}>, fields: list<array<string, mixed>>, removed: list<array<string, mixed>>}
 * @phpstan-type RebuildState array{needs_rebuild: bool, rebuilding: bool, queued: bool, queued_at: ?string}
 */
class Analytics extends Template
{
    private ?FormInterface $form = null;
    /** @var Charts|null */
    private ?array $charts = null;
    /** @var RebuildState|null */
    private ?array $rebuildState = null;

    /** @param array<string, mixed> $data */
    public function __construct(
        Context $context,
        private readonly FormRepositoryInterface $formRepository,
        private readonly Query $query,
        private readonly FreeTextReader $freeTextReader,
        private readonly Config $config,
        private readonly AnalyticsNotices $notices,
        private readonly JsonHexTag $jsonHexTag,
        private readonly GroupSourceInterface $groupSource,
        private readonly RebuildQueue $rebuildQueue,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getFormId(): int
    {
        return (int)$this->getRequest()->getParam('form_id');
    }

    public function isEnabled(): bool
    {
        return $this->config->isAnalyticsEnabled();
    }

    /** @return array<int, \Magento\Framework\Phrase> */
    public function getNotices(): array
    {
        return $this->notices->get($this->getFormId(), (int)$this->getData('pending'));
    }

    public function canQueueRebuild(): bool
    {
        $state = $this->rebuildState();
        return $state['needs_rebuild'] && !$state['queued'] && !$state['rebuilding']
            && $this->_authorization->isAllowed('Furan_FormBuilder::analytics');
    }

    public function getQueuedNotice(): ?\Magento\Framework\Phrase
    {
        $state = $this->rebuildState();
        return $state['queued']
            ? __('A statistics rebuild is queued for the next off-peak run (queued %1).', (string)$state['queued_at'])
            : null;
    }

    public function getQueueRebuildUrl(): string
    {
        return $this->getUrl('furanform/analytics/queueRebuild', ['_query' => ['form_id' => $this->getFormId()]]);
    }

    /** @return array{store_id?: int, customer_group_id?: int, from?: string, to?: string} */
    public function getFilters(): array
    {
        $request = $this->getRequest();
        $filters = [];
        foreach (['store_id', 'customer_group_id'] as $key) {
            $value = $request->getParam($key);
            if (is_string($value) && preg_match('/^\d{1,10}$/', $value)) {
                $filters[$key] = (int)$value;
            }
        }
        foreach (['from', 'to'] as $key) {
            $value = $request->getParam($key);
            if (is_string($value) && $this->isDate($value)) {
                $filters[$key] = $value;
            }
        }
        return $filters;
    }

    /** @return array{from: string, to: string} the range the charts are actually built for */
    public function getEffectiveRange(): array
    {
        $charts = $this->charts();
        return ['from' => (string)$charts['from'], 'to' => (string)$charts['to']];
    }

    public function getDataJson(): string
    {
        $charts = $this->charts();
        return $this->jsonHexTag->serialize([
            'i18n' => [
                'dataTable' => __('Show data table'),
                'answers' => __('Answer'),
                'share' => __('Share'),
                'average' => __('Average'),
                'submissions' => __('Submissions'),
                'period' => __('Period'),
                'value' => __('Count'),
                'answered' => __('answered'),
                'row' => __('Row'),
            ],
            'granularity' => $charts['granularity'],
            'time' => $charts['time'],
            'charts' => $charts['fields'],
        ]);
    }

    public function getSubmissionCount(): int
    {
        return (int)$this->charts()['submissions'];
    }

    /** @return array<string, string> field name => label of fields whose answers may be listed */
    public function getFreeTextLabels(): array
    {
        $labels = [];
        foreach ($this->fields() as $field) {
            $labels[(string)$field['name']] = (string)($field['label'] ?? $field['name']);
        }
        return $labels;
    }

    /** @return array<string, array{answers: array<int, array{id: int, text: string}>, cursor: ?int}> */
    public function getFreeText(): array
    {
        if (!$this->_authorization->isAllowed('Furan_FormBuilder::submissions')) {
            return [];
        }
        $cursor = $this->getRequest()->getParam('cursor');
        $only = $this->getRequest()->getParam('field');
        $paged = is_string($only) && is_string($cursor) && preg_match('/^\d{1,10}$/', $cursor);
        return $this->freeTextReader->read(
            $this->getFormId(),
            $this->fields(),
            array_intersect_key($this->getFilters(), ['store_id' => 1, 'customer_group_id' => 1]),
            $paged ? $only : null,
            $paged ? (int)$cursor : null
        );
    }

    public function getOlderUrl(string $field, int $cursor): string
    {
        $query = ['form_id' => $this->getFormId(), 'field' => $field, 'cursor' => $cursor] + $this->getFilters();
        return $this->getUrl('*/*/index', ['_query' => $query]);
    }

    public function getFormUrl(): string
    {
        return $this->getUrl('*/*/index', ['_query' => ['form_id' => $this->getFormId()]]);
    }

    /** @return array<int, array{value: int, label: string}> */
    public function getStoreOptions(): array
    {
        $options = [];
        foreach ($this->_storeManager->getStores() as $store) {
            $options[] = ['value' => (int)$store->getId(), 'label' => (string)$store->getName()];
        }
        return $options;
    }

    /** @return array<int, array{value: int, label: string}> */
    public function getGroupOptions(): array
    {
        $options = [];
        foreach ($this->groupSource->toOptionArray() as $group) {
            if ((int)$group['value'] !== GroupInterface::CUST_GROUP_ALL) {
                $options[] = ['value' => (int)$group['value'], 'label' => (string)$group['label']];
            }
        }
        return $options;
    }

    public function renderNotices(): string
    {
        return $this->fetchView((string)$this->getTemplateFile('Furan_FormBuilder::analytics/notice.phtml'));
    }

    /** @return RebuildState */
    private function rebuildState(): array
    {
        return $this->rebuildState ??= $this->rebuildQueue->state($this->getFormId());
    }

    /** @return Charts */
    private function charts(): array
    {
        return $this->charts ??= $this->query->charts($this->getFormId(), $this->fields(), $this->getFilters());
    }

    /** @return list<array<string, mixed>> field items in document order, sections flattened */
    private function fields(): array
    {
        $this->form ??= $this->formRepository->getById($this->getFormId());
        return SchemaFields::all($this->form->getSchema());
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
