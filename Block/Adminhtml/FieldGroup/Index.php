<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block\Adminhtml\FieldGroup;

use Furan\FormBuilder\Model\FieldGroup\FieldGroupList;
use Furan\FormBuilder\Model\FieldGroup\GroupUsage;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Index extends Template
{
    /** @var array{rows: list<array{id: int, name: string, description: string, updated_at: string, url: string, forms: int}>, next: ?int}|null */
    private ?array $listing = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly FieldGroupList $list,
        private readonly GroupUsage $usage,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    private function cursor(): int
    {
        return max(0, (int)$this->getRequest()->getParam('after'));
    }

    public function isFirstPage(): bool
    {
        return $this->cursor() === 0;
    }

    /**
     * @return array{rows: list<array{id: int, name: string, description: string, updated_at: string, url: string, forms: int}>, next: ?int}
     */
    public function getListing(): array
    {
        if ($this->listing === null) {
            $page = $this->list->page($this->cursor());
            $counts = $this->usage->counts(array_column($page['rows'], 'id'));
            $rows = [];
            foreach ($page['rows'] as $row) {
                $rows[] = $row + ['url' => $this->getUrl('*/*/edit', ['id' => $row['id']]), 'forms' => $counts[$row['id']] ?? 0];
            }
            $this->listing = ['rows' => $rows, 'next' => $page['next']];
        }
        return $this->listing;
    }

    public function getNextUrl(): string
    {
        return $this->getUrl('*/*/index', ['_query' => ['after' => $this->getListing()['next']]]);
    }

    public function getFirstUrl(): string
    {
        return $this->getUrl('*/*/index');
    }

    public function formatUpdated(string $date): string
    {
        return $date === '' ? '' : (string)$this->formatDate($date, \IntlDateFormatter::MEDIUM);
    }
}
