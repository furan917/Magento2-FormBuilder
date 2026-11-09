<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Webhook;

use Furan\FormBuilder\Model\Admin\PersistKeys;
use Furan\FormBuilder\Model\Webhook\EndpointAdmin;
use Furan\FormBuilder\Model\Webhook\EndpointInputException;
use Furan\FormBuilder\Model\Webhook\EndpointRepository;
use Furan\FormBuilder\Model\Webhook\EndpointView;
use Furan\FormBuilder\Model\Webhook\SecretStash;
use Furan\FormBuilder\Model\Webhook\UrlOrigin;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class Save extends AbstractManage implements HttpPostActionInterface
{
    private const PERSONAL_RESOURCE = 'Furan_FormBuilder::webhooks_personal';
    private const LISTS = ['form_ids', 'events'];
    private const RECEIVER_KEYS = ['url', 'scope_type', 'scope_ids', 'all_forms', 'form_ids', 'field_mode', 'fields', 'events'];

    public function __construct(
        Context $context,
        private readonly EndpointAdmin $admin,
        private readonly SecretStash $stash,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly EndpointRepository $repository
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $post = $this->rawPost();
        $id = (int)(is_scalar($post['id'] ?? null) ? $post['id'] : 0) ?: null;
        $data = $this->posted($post);
        $redirect = $this->resultRedirectFactory->create();
        try {
            $secret = $this->admin->save(
                $data,
                $id,
                $this->_authorization->isAllowed(self::PERSONAL_RESOURCE),
                $this->adminId()
            );
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setPath('*/*/');
        } catch (LocalizedException $e) {
            $fieldErrors = $e instanceof EndpointInputException ? $e->fieldErrors() : [];
            foreach ($fieldErrors ?: [$e->getMessage()] as $message) {
                $this->messageManager->addErrorMessage($message);
            }
            $this->persistFailed($post, $data, $id, $fieldErrors);
            return $redirect->setPath('*/*/edit', $id ? ['id' => $id] : []);
        }
        $this->dataPersistor->clear(PersistKeys::webhook($id));
        if ($secret !== null) {
            $this->stash->put($secret->endpointId(), $secret->plain());
            $this->messageManager->addSuccessMessage(__('The webhook endpoint has been created. Copy its secret now.'));
            return $redirect->setPath('*/*/edit', ['id' => $secret->endpointId()]);
        }
        $this->messageManager->addSuccessMessage(__('The webhook endpoint has been saved.'));
        return $redirect->setPath('*/*/edit', ['id' => $id]);
    }

    /** @return array<string, mixed> */
    private function rawPost(): array
    {
        return (array)$this->httpRequest()->getPostValue();
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function posted(array $post): array
    {
        $data = [];
        foreach (['name', 'url', 'scope_type', 'field_mode'] as $key) {
            $data[$key] = is_string($post[$key] ?? null) ? $post[$key] : '';
        }
        foreach (['is_active', 'all_forms', 'include_personal', 'debug_responses'] as $key) {
            $data[$key] = !empty($post[$key]);
        }
        foreach (self::LISTS as $key) {
            $data[$key] = is_array($post[$key] ?? null) ? array_values($post[$key]) : [];
        }
        $scopeKey = $data['scope_type'] === 'website' ? 'website_ids' : 'store_ids';
        $data['scope_ids'] = is_array($post[$scopeKey] ?? null) ? array_values($post[$scopeKey]) : [];
        $names = preg_split('/[\s,]+/', trim($this->rawFields($post))) ?: [];
        $data['fields'] = array_values(array_filter($names, static fn ($n): bool => $n !== ''));

        return $data;
    }

    /** @param array<string, mixed> $post */
    private function rawFields(array $post): string
    {
        return is_string($post['fields'] ?? null) ? $post['fields'] : '';
    }

    /**
     * Keeps what is safe to show again: no URL path or query (they may hold a token), and no receiver
     * settings of a locked endpoint.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $data
     * @param array<string, string> $fieldErrors
     */
    private function persistFailed(array $post, array $data, ?int $id, array $fieldErrors): void
    {
        $stored = $this->storedView($id);
        $data['fields'] = $this->rawFields($post);
        if ($stored !== null && $stored->includePersonal() && !$this->_authorization->isAllowed(self::PERSONAL_RESOURCE)) {
            $data = array_diff_key($data, array_flip(self::RECEIVER_KEYS));
        } elseif ($stored !== null && $stored->url() === $data['url']) {
            unset($data['url']);
        } else {
            $origin = UrlOrigin::of((string)$data['url']);
            if ($origin !== $data['url'] && $data['url'] !== '') {
                $this->messageManager->addErrorMessage(
                    __('For security, only the start of the URL was kept. Enter the full URL again.')
                );
            }
            $data['url'] = $origin;
        }
        $data['field_errors'] = $fieldErrors;
        $this->dataPersistor->set(PersistKeys::webhook($id), $data);
    }

    private function storedView(?int $id): ?EndpointView
    {
        if ($id === null) {
            return null;
        }
        try {
            return $this->repository->getView($id);
        } catch (NoSuchEntityException) {
            return null;
        }
    }
}
