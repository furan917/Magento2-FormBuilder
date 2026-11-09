<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block\Adminhtml\Webhook;

use Furan\FormBuilder\Model\Webhook\EndpointRepository;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Index extends Template
{
    private const MANAGE = 'Furan_FormBuilder::webhooks_manage';
    private const EXPORT = 'Furan_FormBuilder::submissions_export';

    /** @var array{rows: list<array{id: int, name: string, host: string, active: bool, reason: string, url: string, log_url: string}>, next: ?int}|null */
    private ?array $listing = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly EndpointRepository $repository,
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

    public function canAdd(): bool
    {
        return $this->_authorization->isAllowed(self::MANAGE) && $this->_authorization->isAllowed(self::EXPORT);
    }

    /**
     * The receiver is shown by host only; the path and query can carry a token.
     *
     * @return array{rows: list<array{id: int, name: string, host: string, active: bool, reason: string, url: string, log_url: string}>, next: ?int}
     */
    public function getListing(): array
    {
        if ($this->listing === null) {
            $page = $this->repository->page($this->cursor());
            $rows = [];
            foreach ($page['rows'] as $view) {
                $rows[] = [
                    'id' => $view->id(),
                    'name' => $view->name(),
                    'host' => (string)parse_url($view->url(), PHP_URL_HOST), // phpcs:ignore Magento2.Functions.DiscouragedFunction -- no framework host parser; null host cast to ''
                    'active' => $view->isActive(),
                    'reason' => $this->reasonLabel($view->disabledReason()),
                    'url' => $this->getUrl('*/*/edit', ['id' => $view->id()]),
                    'log_url' => $this->getUrl('*/webhookDelivery/index', ['id' => $view->id()]),
                ];
            }
            $this->listing = ['rows' => $rows, 'next' => $page['next']];
        }

        return $this->listing;
    }

    private function reasonLabel(?string $reason): string
    {
        return match ($reason) {
            null => '',
            'gone' => (string)__('The receiver answered 410 Gone'),
            'failing' => (string)__('Deliveries kept failing'),
            'manual' => (string)__('Disabled by an admin'),
            'secret_unavailable' => (string)__('The secret cannot be read'),
            default => (string)__('Disabled'),
        };
    }

    public function getNewUrl(): string
    {
        return $this->getUrl('*/*/newAction');
    }

    public function getNextUrl(): string
    {
        return $this->getUrl('*/*/index', ['_query' => ['after' => $this->getListing()['next']]]);
    }

    public function getFirstUrl(): string
    {
        return $this->getUrl('*/*/index');
    }
}
