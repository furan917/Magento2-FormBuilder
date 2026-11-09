<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block\Adminhtml\WebhookDelivery;

use Furan\FormBuilder\Model\Webhook\DeliveryLog;
use Furan\FormBuilder\Model\Webhook\EndpointRepository;
use Furan\FormBuilder\Model\Webhook\EndpointView;
use Furan\FormBuilder\Model\Webhook\WebhookAccess;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Exception\NoSuchEntityException;

class View extends Template
{
    private const REPLAY = 'Furan_FormBuilder::webhooks_replay';
    private const SUBMISSIONS = 'Furan_FormBuilder::submissions';

    /** @var array{delivery: array<string, mixed>, attempts: list<array<string, mixed>>}|null|false */
    private $detail = false;

    private ?EndpointView $endpoint = null;

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

    public function getDeliveryId(): int
    {
        return max(0, (int)$this->getRequest()->getParam('id'));
    }

    /**
     * Not found when the endpoint is gone or being purged. The endpoint is loaded before the attempts, so the
     * response excerpt is only selected for admins who may read it.
     *
     * @return array{delivery: array<string, mixed>, attempts: list<array<string, mixed>>}|null
     */
    public function getDetail(): ?array
    {
        if ($this->detail === false) {
            $this->detail = $this->load();
        }

        return $this->detail;
    }

    /** @return array{delivery: array<string, mixed>, attempts: list<array<string, mixed>>}|null */
    private function load(): ?array
    {
        $delivery = $this->log->delivery($this->getDeliveryId());
        $endpoint = $delivery === null ? null : $this->endpointOf($delivery);
        if ($delivery === null || $endpoint === null) {
            return null;
        }
        $withExcerpt = $endpoint->debugResponses() && WebhookAccess::canReadResponses($this->_authorization, $endpoint);

        return ['delivery' => $delivery, 'attempts' => $this->log->attempts((int)$delivery['id'], $withExcerpt)];
    }

    public function getEndpointName(): string
    {
        $detail = $this->getDetail();

        return $detail === null ? '' : ($this->endpointOf($detail['delivery'])?->name() ?? '');
    }

    public function canOpenSubmissions(): bool
    {
        return $this->_authorization->isAllowed(self::SUBMISSIONS);
    }

    public function canReplay(): bool
    {
        return $this->_authorization->isAllowed(self::REPLAY) && $this->_authorization->isAllowed(WebhookAccess::VIEW);
    }

    public function getSubmissionUrl(int $submissionId): string
    {
        return $this->getUrl('*/submission/view', ['id' => $submissionId]);
    }

    public function getBackUrl(): string
    {
        $detail = $this->getDetail();

        return $detail === null
            ? $this->getUrl('*/webhook/')
            : $this->getUrl('*/*/index', ['id' => (int)$detail['delivery']['endpoint_id']]);
    }

    public function getRedeliverUrl(): string
    {
        return $this->getUrl('*/*/redeliver');
    }

    public function getResendUrl(): string
    {
        return $this->getUrl('*/*/resend');
    }

    /** @param array<string, mixed> $delivery */
    private function endpointOf(array $delivery): ?EndpointView
    {
        if ($this->endpoint === null) {
            try {
                $this->endpoint = $this->repository->getView((int)$delivery['endpoint_id']);
            } catch (NoSuchEntityException) {
                return null;
            }
        }

        return $this->endpoint;
    }
}
