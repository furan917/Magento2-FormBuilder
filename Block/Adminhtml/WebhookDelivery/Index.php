<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block\Adminhtml\WebhookDelivery;

use Furan\FormBuilder\Model\Webhook\DeliveryLog;
use Furan\FormBuilder\Model\Webhook\DeliveryStatus;
use Furan\FormBuilder\Model\Webhook\EndpointRepository;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Exception\NoSuchEntityException;

class Index extends Template
{
    private const SUBMISSIONS = 'Furan_FormBuilder::submissions';
    private const FIND_MAX = 100;

    /** @var array{rows: list<array<string, mixed>>, next: ?int, unrecognised: bool}|null */
    private ?array $listing = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly EndpointRepository $repository,
        private readonly DeliveryLog $log,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getEndpointId(): int
    {
        return max(0, (int)$this->getRequest()->getParam('id'));
    }

    /** Empty when the endpoint is gone or being purged. */
    public function getEndpointName(): ?string
    {
        try {
            return $this->repository->getView($this->getEndpointId())->name();
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    private function cursor(): int
    {
        $after = $this->getRequest()->getParam('after');

        return is_string($after) && ctype_digit($after) ? (int)$after : 0;
    }

    public function isFirstPage(): bool
    {
        return $this->cursor() === 0;
    }

    public function getStatus(): ?int
    {
        $status = $this->getRequest()->getParam('status');

        return is_string($status) && ctype_digit($status) && in_array((int)$status, DeliveryStatus::STATUSES, true)
            ? (int)$status
            : null;
    }

    public function getFind(): string
    {
        $find = $this->getRequest()->getParam('find');

        return is_string($find) ? mb_substr(trim($find), 0, self::FIND_MAX) : '';
    }

    public function isFiltered(): bool
    {
        return $this->getStatus() !== null || $this->getFind() !== '';
    }

    /** @return array<int, string> status => label */
    public function getStatusOptions(): array
    {
        $options = [];
        foreach (DeliveryStatus::STATUSES as $status) {
            $options[$status] = DeliveryStatus::label($status);
        }

        return $options;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, next: ?int, unrecognised: bool}
     */
    public function getListing(): array
    {
        if ($this->listing === null && $this->getEndpointName() === null) {
            $this->listing = ['rows' => [], 'next' => null, 'unrecognised' => false];
        }
        if ($this->listing === null) {
            $page = $this->log->page($this->getEndpointId(), $this->cursor(), 50, $this->getStatus(), $this->getFind());
            $canOpen = $this->_authorization->isAllowed(self::SUBMISSIONS);
            foreach ($page['rows'] as $i => $row) {
                $page['rows'][$i]['view_url'] = $this->getUrl('*/*/view', ['id' => $row['id']]);
                $page['rows'][$i]['submission_url'] = $canOpen && $row['submission_id'] !== null
                    ? $this->getUrl('*/submission/view', ['id' => $row['submission_id']])
                    : null;
            }
            $this->listing = $page;
        }

        return $this->listing;
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('*/webhook/edit', ['id' => $this->getEndpointId()]);
    }

    /** The unfiltered first page; also the filter form's action, which adds status and find itself. */
    public function getClearUrl(): string
    {
        return $this->getUrl('*/*/index', ['id' => $this->getEndpointId()]);
    }

    public function getFirstUrl(): string
    {
        return $this->getUrl('*/*/index', ['id' => $this->getEndpointId(), '_query' => $this->filterQuery()]);
    }

    public function getNextUrl(): string
    {
        return $this->getUrl(
            '*/*/index',
            ['id' => $this->getEndpointId(), '_query' => ['after' => $this->getListing()['next']] + $this->filterQuery()]
        );
    }

    /** @return array<string, int|string> */
    private function filterQuery(): array
    {
        return array_filter(
            ['status' => $this->getStatus(), 'find' => $this->getFind()],
            static fn ($v): bool => $v !== null && $v !== ''
        );
    }
}
