<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block\Adminhtml\Rule;

use Furan\FormBuilder\Model\Validation\BuiltInRules;
use Furan\FormBuilder\Model\Validation\SavedRuleList;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Index extends Template
{
    /** @param array<string, mixed> $data */
    public function __construct(
        Context $context,
        private readonly SavedRuleList $list,
        private readonly BuiltInRules $builtIn,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @var array{q: string, usage: string, sort: string, limit: int, page: int}|null */
    private ?array $filters = null;
    /** @var array{rows: list<array<string, mixed>>, total: int, all: int, page: int, pages: int, from: int, to: int}|null */
    private ?array $listing = null;

    /** @return array{q: string, usage: string, sort: string, limit: int, page: int} */
    public function getFilters(): array
    {
        return $this->filters ??= SavedRuleList::filters($this->getRequest()->getParams());
    }

    public function hasActiveFilters(): bool
    {
        $f = $this->getFilters();
        return $f['q'] !== '' || $f['usage'] !== 'all';
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, all: int, page: int, pages: int, from: int, to: int}
     */
    public function getListing(): array
    {
        if ($this->listing === null) {
            $this->listing = $this->list->page($this->getFilters());
            foreach ($this->listing['rows'] as &$row) {
                $row['url'] = $this->getUrl('*/*/edit', ['id' => (int)($row['id'] ?? 0)]);
            }
            unset($row);
        }
        return $this->listing;
    }

    /**
     * List URL with the current filters, overridden by $changes (null removes a parameter).
     *
     * @param array<string, mixed> $changes
     */
    public function getListUrl(array $changes = []): string
    {
        $f = $this->getFilters();
        $query = array_filter(array_merge([
            'q' => $f['q'],
            'usage' => $f['usage'] === 'all' ? null : $f['usage'],
            'sort' => $f['sort'] === 'name' ? null : $f['sort'],
            'limit' => $f['limit'] === SavedRuleList::LIMITS[0] ? null : $f['limit'],
        ], $changes), static fn ($v): bool => $v !== null && $v !== '' && $v !== 1);
        return $this->getUrl('*/*/index', ['_query' => $query]);
    }

    public function getClearUrl(): string
    {
        return $this->getUrl('*/*/index');
    }

    public function formatUpdated(string $date): string
    {
        return $date === '' ? '' : (string)$this->formatDate($date, \IntlDateFormatter::MEDIUM);
    }

    /**
     * @return array<string, array<int, array{code: string, label: string, example: string, pattern: string, copy_url: string}>>
     */
    public function getBuiltInGroups(): array
    {
        $groups = [];
        foreach ($this->builtIn->all() as $code => $rule) {
            $groups[(string)__($rule['group'])][] = [
                'code' => $code,
                'label' => (string)__($rule['label']),
                'example' => $rule['example'],
                'pattern' => $rule['pattern'],
                'copy_url' => $this->getUrl('*/*/edit', ['from' => $code]),
            ];
        }
        return $groups;
    }

    public function canSave(): bool
    {
        return $this->_authorization->isAllowed('Furan_FormBuilder::rules_save');
    }

    public function getNewUrl(): string
    {
        return $this->getUrl('*/*/edit');
    }
}
