<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block\Adminhtml\Submission;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\Data\DisplayContext;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Model\Customer\CustomerSummary;
use Furan\FormBuilder\Model\Source\SubmissionStatus;
use Furan\FormBuilder\Model\Submission\AnswerDate;
use Furan\FormBuilder\Model\Submission\AnswerView;
use Furan\FormBuilder\Model\Thread\MessageRenderer;
use Furan\FormBuilder\Model\Thread\Messages;
use Magento\Backend\Block\Widget\Context;
use Magento\Backend\Block\Widget\Container;
use Magento\Directory\Model\Currency;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;

class View extends Container
{
    private ?SubmissionInterface $submission = null;
    private ?FormInterface $form = null;
    /** @var array{type: string, name: string, email: string, group: string, orders: ?array{count: int, lifetime: float, lastOrderAt: ?string}, others: array<int, array<string, mixed>>}|null */
    private ?array $customerCard = null;
    /** @var array{messages: array<int, array<string, mixed>>, earlier: bool}|null */
    private ?array $threadPage = null;
    private ?DisplayContext $displayContext = null;

    /** @param array<string, mixed> $data */
    public function __construct(
        Context $context,
        private readonly SubmissionRepositoryInterface $submissionRepository,
        private readonly FormRepositoryInterface $formRepository,
        private readonly AnswerView $answerView,
        private readonly SubmissionStatus $statuses,
        private readonly Messages $messages,
        private readonly CustomerSummary $customerSummary,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly MessageRenderer $renderer,
        private readonly AnswerDate $answerDate,
        private readonly ResolverInterface $localeResolver,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _construct()
    {
        parent::_construct();
        $id = $this->getSubmissionId();
        $this->addButton('back', [
            'label' => __('Back'),
            'onclick' => 'setLocation(\'' . $this->_escaper->escapeJs($this->getBackUrl()) . '\')',
            'class' => 'back',
        ]);
        if ($id > 0 && $this->_authorization->isAllowed('Furan_FormBuilder::submissions_delete')) {
            $this->addButton('delete', [
                'label' => __('Delete'),
                'class' => 'delete',
                'onclick' => 'deleteConfirm(\'' . $this->_escaper->escapeJs((string)__('Are you sure you want to delete this submission?'))
                    . '\', \'' . $this->_escaper->escapeJs($this->getUrl('*/*/delete', ['id' => $id])) . '\', {"data": {}})',
            ]);
        }
    }

    public function getSubmission(): ?SubmissionInterface
    {
        if ($this->submission === null) {
            try {
                $this->submission = $this->submissionRepository->getById($this->getSubmissionId());
            } catch (NoSuchEntityException) {
                return null;
            }
        }
        return $this->submission;
    }

    /** @return array<int, array{name: string, label: string, type: string, value: mixed}> */
    public function getPayload(): array
    {
        $submission = $this->getSubmission();
        return $submission?->getPayload() ?? [];
    }

    public function getFormTitle(): string
    {
        return $this->getForm()?->getTitle() ?? '';
    }

    /**
     * @return list<array{title: string, removed: bool, sections: list<array{title: string, entries: list<array<string, mixed>>}>}>
     */
    public function getAnswerGroups(): array
    {
        return $this->answerView->group($this->getForm()?->getSchema() ?? [], $this->getPayload());
    }

    /**
     * @param array<string, mixed> $entry
     * @return string[]
     */
    public function getDisplayValues(array $entry): array
    {
        return $this->answerView->values($entry, $this->displayContext());
    }

    /**
     * Escaped HTML supplied by the field type itself, or null for types that render through the template.
     *
     * @param array<string, mixed> $entry
     */
    public function getDisplayHtml(array $entry, Escaper $escaper): ?string
    {
        return $this->answerView->html($entry, $escaper, $this->displayContext());
    }

    /**
     * Layout kind (`Api\FieldType\AdminViewTypeInterface::KIND_*`) of an answer, null for plain text.
     *
     * @param array<string, mixed> $entry
     */
    public function getDisplayKind(array $entry): ?string
    {
        return $this->answerView->kind($entry);
    }

    /**
     * The answer as a date in the admin locale, or null when it is not a valid date answer.
     *
     * @param array<string, mixed> $entry
     */
    public function getDisplayDate(array $entry): ?string
    {
        return $this->answerDate->text(
            $this->answerView->kind($entry),
            $entry['value'] ?? null,
            $this->displayContext()->locale
        );
    }

    /**
     * A server-owned answer the visitor never saw as a question.
     *
     * @param array<string, mixed> $entry
     */
    public function isHidden(array $entry): bool
    {
        return $this->answerView->isServerValue($entry);
    }

    /** @param array<string, mixed> $entry */
    public function isBrowserValue(array $entry): bool
    {
        return $this->answerView->isBrowserValue($entry);
    }

    /** @param array<string, mixed> $entry */
    public function isFile(array $entry): bool
    {
        return $this->answerView->isFile($entry);
    }

    /** @param array<string, mixed> $entry */
    public function isAnswered(array $entry): bool
    {
        return $this->answerView->isAnswered($entry, $this->displayContext());
    }

    public function getSubmittedAt(): string
    {
        $createdAt = (string)$this->getSubmission()?->getCreatedAt();
        return $createdAt === '' ? '' : (string)$this->formatDate($createdAt, \IntlDateFormatter::MEDIUM, true);
    }

    public function getStoreName(): string
    {
        $storeId = (int)$this->getSubmission()?->getStoreId();
        try {
            return (string)$this->_storeManager->getStore($storeId)->getName();
        } catch (NoSuchEntityException) {
            return (string)__('Store view %1', $storeId);
        }
    }

    public function getFormUrl(): string
    {
        $form = $this->getForm();
        if ($form === null || !$this->_authorization->isAllowed('Furan_FormBuilder::forms')) {
            return '';
        }
        return $this->getUrl('*/form/edit', ['id' => (int)$form->getId()]);
    }

    public function getAnalyticsUrl(): string
    {
        $submission = $this->getSubmission();
        if ($submission === null || !$this->_authorization->isAllowed('Furan_FormBuilder::analytics')) {
            return '';
        }
        return $this->getUrl('*/analytics/index', ['form_id' => $submission->getFormId()]);
    }

    public function getCustomerUrl(): string
    {
        $customerId = (int)$this->getSubmission()?->getCustomerId();
        if ($customerId <= 0 || !$this->_authorization->isAllowed('Magento_Customer::manage')) {
            return '';
        }
        return $this->getUrl('customer/index/edit', ['id' => $customerId]);
    }

    /**
     * @return array{type: string, name: string, email: string, group: string,
     *     orders: ?array{count: int, lifetime: float, lastOrderAt: ?string}, others: array<int, array<string, mixed>>}|null
     */
    public function getCustomerCard(): ?array
    {
        $submission = $this->getSubmission();
        if ($submission === null) {
            return null;
        }
        return $this->customerCard ??= $this->customerSummary->card(
            $submission,
            $this->getForm()?->getSettings() ?? [],
            $this->_authorization->isAllowed('Magento_Sales::sales_order')
        );
    }

    public function formatLifetime(float $amount): string
    {
        $base = (string)$this->_scopeConfig->getValue(Currency::XML_PATH_CURRENCY_BASE);
        return (string)$this->priceCurrency->format($amount, false, PriceCurrencyInterface::DEFAULT_PRECISION, null, $base ?: null);
    }

    public function formatSubmissionDate(string $createdAt): string
    {
        return (string)$this->formatDate($createdAt, \IntlDateFormatter::MEDIUM, true);
    }

    public function getSubmissionUrl(int $id): string
    {
        return $this->getUrl('*/*/view', ['id' => $id]);
    }

    /** @return array{name: string, sku: string, url: string, price: ?float, currency: string}|null */
    public function getProductContext(): ?array
    {
        return $this->customerSummary->productContext($this->getSubmission()?->getContext());
    }

    public function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return max(1, (int)round($bytes / 1024)) . ' KB';
    }

    /** Empty when the admin user may not download uploaded files. */
    public function getDownloadUrl(string $field): string
    {
        if (!$this->_authorization->isAllowed('Furan_FormBuilder::submissions_files')) {
            return '';
        }
        return $this->getUrl('*/*/download', ['id' => $this->getSubmissionId(), 'field' => $field]);
    }

    public function canChangeStatus(): bool
    {
        return $this->_authorization->isAllowed('Furan_FormBuilder::submissions_reply');
    }

    public function getCurrentStatus(): int
    {
        return (int)$this->getSubmission()?->getStatus();
    }

    public function getStatusLabel(): string
    {
        return (string)$this->statuses->getLabel($this->getCurrentStatus());
    }

    /**
     * @return array<int, array{value: int|string, label: \Magento\Framework\Phrase|string}>
     */
    public function getStatusOptions(): array
    {
        return $this->statuses->toOptionArray();
    }

    public function getSaveStatusUrl(): string
    {
        return $this->getUrl('*/*/saveStatus', ['id' => $this->getSubmissionId()]);
    }

    public function canReply(): bool
    {
        return $this->_authorization->isAllowed('Furan_FormBuilder::submissions_reply');
    }

    public function getReplyUrl(): string
    {
        return $this->getUrl('*/*/reply', ['id' => $this->getSubmissionId()]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getThread(): array
    {
        return $this->threadPage()['messages'];
    }

    public function hasEarlierMessages(): bool
    {
        return $this->threadPage()['earlier'];
    }

    public function getBefore(): ?int
    {
        return Messages::beforeParam($this->getRequest()->getParam('before'));
    }

    public function getEarlierUrl(int $beforeId): string
    {
        return $this->getUrl('*/*/view', ['id' => $this->getSubmissionId(), 'before' => $beforeId, '_fragment' => 'ffb-thread']);
    }

    public function getLatestUrl(): string
    {
        return $this->getUrl('*/*/view', ['id' => $this->getSubmissionId(), '_fragment' => 'ffb-thread']);
    }

    /** @param array<string, mixed> $message */
    public function renderMessage(array $message): string
    {
        return $this->renderer->html((string)($message['author'] ?? ''), (string)($message['body'] ?? ''));
    }

    public function getRevokeLinksUrl(): string
    {
        return $this->getUrl('*/*/revokeLinks', ['id' => $this->getSubmissionId()]);
    }

    public function formatMessageDate(string $createdAt): string
    {
        return (string)$this->formatDate($createdAt, \IntlDateFormatter::MEDIUM, true);
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('*/*/');
    }

    /** @return array{messages: array<int, array<string, mixed>>, earlier: bool} */
    private function threadPage(): array
    {
        return $this->threadPage ??= $this->messages->page($this->getSubmissionId(), $this->getBefore());
    }

    private function getForm(): ?FormInterface
    {
        $submission = $this->getSubmission();
        if ($this->form === null && $submission !== null) {
            try {
                $this->form = $this->formRepository->getById($submission->getFormId());
            } catch (NoSuchEntityException) {
                return null;
            }
        }
        return $this->form;
    }

    private function getSubmissionId(): int
    {
        return (int)$this->getRequest()->getParam('id');
    }

    private function displayContext(): DisplayContext
    {
        return $this->displayContext ??= DisplayContext::forAdmin($this->localeResolver->getLocale());
    }
}
