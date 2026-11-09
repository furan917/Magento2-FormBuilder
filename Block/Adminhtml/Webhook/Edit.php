<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block\Adminhtml\Webhook;

use Furan\FormBuilder\Model\Admin\PersistKeys;
use Furan\FormBuilder\Model\Source\AllForms;
use Furan\FormBuilder\Model\Webhook\DeliveryStatus;
use Furan\FormBuilder\Model\Webhook\EndpointInput;
use Furan\FormBuilder\Model\Webhook\EndpointRepository;
use Furan\FormBuilder\Model\Webhook\EndpointView;
use Furan\FormBuilder\Model\Webhook\SecretStash;
use Furan\FormBuilder\Model\Webhook\UrlOrigin;
use Furan\FormBuilder\Model\Webhook\WebhookAccess;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class Edit extends Template
{
    private const PERSONAL = 'Furan_FormBuilder::webhooks_personal';

    /** @var array<string, mixed>|null */
    private ?array $values = null;
    private ?EndpointView $view = null;
    private bool $secretRead = false;
    private ?string $secret = null;
    /** @var array<string, string> */
    private array $fieldErrors = [];
    private bool $persistedRead = false;
    /** @var array<string, mixed> */
    private array $persisted = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly EndpointRepository $repository,
        private readonly SecretStash $stash,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly AllForms $forms,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getEndpointId(): int
    {
        return (int)$this->getRequest()->getParam('id');
    }

    public function isNew(): bool
    {
        return $this->getEndpointId() <= 0;
    }

    /** An endpoint is a continuous export, so changing one needs the export permission too. */
    public function isReadOnly(): bool
    {
        return !WebhookAccess::canChange($this->_authorization);
    }

    public function canChangePersonal(): bool
    {
        return !$this->isReadOnly() && $this->_authorization->isAllowed(self::PERSONAL);
    }

    /** Where a personal data endpoint sends its data is only for admins who may send personal data. */
    public function isReceiverLocked(): bool
    {
        $view = $this->stored();

        return $view !== null && $view->includePersonal() && !$this->_authorization->isAllowed(self::PERSONAL);
    }

    /** The new secret of a just-created endpoint, once; never for read-only admins. */
    public function getRevealedSecret(): ?string
    {
        if (!$this->secretRead && !$this->isReadOnly() && !$this->isNew()) {
            $this->secretRead = true;
            $this->secret = $this->stash->peek($this->getEndpointId());
        }

        return $this->secret;
    }

    /** Removes the secret only after the page rendered, so a render failure cannot lose it. */
    protected function _afterToHtml($html)
    {
        if ($this->secret !== null) {
            $this->stash->take($this->getEndpointId());
            $this->secret = null;
        }

        return parent::_afterToHtml($html);
    }

    /**
     * A failed save's input, else the stored endpoint, else defaults. Read-only admins get the URL without its query.
     *
     * @return array<string, mixed>
     */
    public function getValues(): array
    {
        if ($this->values === null) {
            $values = $this->storedValues();
            $values = array_intersect_key($this->persisted(), $values) + $values;
            if ($this->isReadOnly()) {
                $values['url'] = $this->maskUrl((string)$values['url']);
            }
            $this->values = $values;
        }

        return $this->values;
    }

    public function getFieldError(string $field): ?string
    {
        $this->persisted();

        return $this->fieldErrors[$field] ?? null;
    }

    /** `aria-invalid` and `aria-describedby` for a control; fieldsets pass `$invalid = false`. */
    public function getFieldAria(string $field, string $hintId = '', bool $invalid = true): string
    {
        $failed = $this->getFieldError($field) !== null;
        $ids = array_filter([$hintId, $failed ? $this->errorId($field) : '']);

        return ($failed && $invalid ? ' aria-invalid="true"' : '')
            . ($ids ? ' aria-describedby="' . $this->_escaper->escapeHtml(implode(' ', $ids)) . '"' : '');
    }

    public function getFieldErrorHtml(string $field): string
    {
        $error = $this->getFieldError($field);

        return $error === null ? '' : '<p class="ffb-rules-error" id="' . $this->_escaper->escapeHtmlAttr($this->errorId($field)) . '">'
            . $this->_escaper->escapeHtml($error) . '</p>';
    }

    /** Shows the host only, as the list does; an ellipsis marks a hidden path or query. */
    public function maskUrl(string $url): string
    {
        $origin = UrlOrigin::of($url);

        return $origin === '' || $url === $origin ? $origin : $origin . '/…';
    }

    /** @return array<int, string> */
    public function getWebsiteOptions(): array
    {
        $options = [];
        foreach ($this->_storeManager->getWebsites() as $website) {
            $options[(int)$website->getId()] = (string)$website->getName();
        }

        return $options;
    }

    /** @return array<int, string> */
    public function getStoreOptions(): array
    {
        $options = [];
        foreach ($this->_storeManager->getStores() as $store) {
            $options[(int)$store->getId()] = (string)$store->getName() . ' (' . $store->getCode() . ')';
        }

        return $options;
    }

    /** @return array<int, string> */
    public function getFormOptions(): array
    {
        $options = [];
        foreach ($this->forms->toOptionArray() as $option) {
            $options[(int)$option['value']] = (string)$option['label'];
        }

        return $options;
    }

    /** @return array<string, string> */
    public function getEventOptions(): array
    {
        return array_combine(
            EndpointInput::EVENTS,
            array_map(DeliveryStatus::eventLabel(...), EndpointInput::EVENTS)
        );
    }

    public function getDisabledReasonLabel(): string
    {
        $reason = $this->stored()?->disabledReason();

        return match ($reason) {
            null => '',
            'gone' => (string)__('Disabled: the receiver answered 410 Gone.'),
            'failing' => (string)__('Disabled: deliveries kept failing.'),
            'manual' => (string)__('Disabled by an admin.'),
            'secret_unavailable' => (string)__('Disabled: the secret cannot be read. Run bin/magento furanform:webhooks:reencrypt, then turn the endpoint on again.'),
            default => (string)__('Disabled.'),
        };
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('*/*/save');
    }

    public function getDeleteUrl(): string
    {
        return $this->getUrl('*/*/delete');
    }

    public function getRotateUrl(): string
    {
        return $this->getUrl('*/*/rotateSecret');
    }

    public function getTestSendUrl(): string
    {
        return $this->getUrl('*/*/testSend');
    }

    public function getDeliveryLogUrl(): string
    {
        return $this->getUrl('*/webhookDelivery/index', ['id' => $this->getEndpointId()]);
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('*/*/');
    }

    /**
     * The failed save's input for this endpoint, read once and cleared.
     *
     * @return array<string, mixed>
     */
    private function persisted(): array
    {
        if ($this->persistedRead) {
            return $this->persisted;
        }
        $this->persistedRead = true;
        $key = PersistKeys::webhook($this->isNew() ? null : $this->getEndpointId());
        $data = $this->dataPersistor->get($key);
        if (is_array($data)) {
            $this->dataPersistor->clear($key);
            $errors = $data['field_errors'] ?? [];
            $this->fieldErrors = is_array($errors) ? array_map('strval', $errors) : [];
            unset($data['field_errors']);
            $this->persisted = $data;
        }

        return $this->persisted;
    }

    private function errorId(string $field): string
    {
        return 'ffb-webhook-' . $field . '-error';
    }

    private function stored(): ?EndpointView
    {
        if ($this->view === null && !$this->isNew()) {
            try {
                $this->view = $this->repository->getView($this->getEndpointId());
            } catch (NoSuchEntityException) {
                return null;
            }
        }

        return $this->view;
    }

    /** @return array<string, mixed> */
    private function storedValues(): array
    {
        $view = $this->stored();
        if ($view === null) {
            return [
                'name' => '', 'url' => '', 'is_active' => true, 'scope_type' => 'store', 'scope_ids' => [],
                'all_forms' => false, 'form_ids' => [], 'events' => EndpointInput::EVENTS, 'field_mode' => 'all',
                'fields' => '', 'include_personal' => false, 'debug_responses' => false,
            ];
        }

        return [
            'name' => $view->name(), 'url' => $view->url(), 'is_active' => $view->isActive(),
            'scope_type' => $view->scopeType(), 'scope_ids' => $view->scopeIds(), 'all_forms' => $view->allForms(),
            'form_ids' => $view->formIds(), 'events' => $view->events(), 'field_mode' => $view->fieldMode(),
            'fields' => implode("\n", $view->fields()), 'include_personal' => $view->includePersonal(),
            'debug_responses' => $view->debugResponses(),
        ];
    }
}
