<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block\Account;

use Furan\FormBuilder\Model\Account\SubmissionReader;
use Furan\FormBuilder\Model\Survey\Respondents;
use Magento\Customer\Model\Session;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class Submissions extends Template
{
    public const PAGE_SIZE = 20;

    /** @var array{rows: array<int, array<string, mixed>>, older: ?int}|null */
    private ?array $page = null;

    /** @param array<string, mixed> $data */
    public function __construct(
        Context $context,
        private readonly SubmissionReader $reader,
        private readonly Session $session,
        private readonly Respondents $respondents,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @return array{rows: array<int, array<string, mixed>>, older: ?int} */
    public function getPage(): array
    {
        return $this->page ??= $this->reader->listPage(
            (int)$this->session->getCustomerId(),
            (int)$this->getRequest()->getParam('before'),
            self::PAGE_SIZE
        );
    }

    /** Deleting answers to one-response forms does not reopen them, so the delete-all prompt says so. */
    public function hasOneResponseAnswers(): bool
    {
        $customerId = (int)$this->session->getCustomerId();
        return $customerId > 0 && $this->respondents->hasAnswered($this->respondents->customerKey($customerId));
    }

    public function getViewUrl(int $submissionId): string
    {
        return $this->getUrl('furanform/account/submission', ['id' => $submissionId]);
    }

    public function getExportUrl(): string
    {
        return $this->getUrl('furanform/account/export');
    }

    public function getDeleteAllUrl(): string
    {
        return $this->getUrl('furanform/account/deleteall');
    }

    public function getOlderUrl(): ?string
    {
        $older = $this->getPage()['older'];
        return $older === null ? null : $this->getUrl('furanform/account/submissions', ['before' => $older]);
    }

    public function getStatusLabel(int $status): string
    {
        return match ($status) {
            1 => (string)__('In progress'),
            2 => (string)__('Answered'),
            3 => (string)__('Closed'),
            default => (string)__('New'),
        };
    }
}
