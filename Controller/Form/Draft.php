<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Form;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\FormKeyCsrf;
use Furan\FormBuilder\Controller\NoStoreHeaders;
use Furan\FormBuilder\Model\Access\FormAccess;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Customer\Impersonation;
use Furan\FormBuilder\Model\Draft\DraftStore;
use Furan\FormBuilder\Model\Form\ScheduleGate;
use Furan\FormBuilder\Model\I18n\Translator;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Draft implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    use FormKeyCsrf;
    use NoStoreHeaders;

    private const MAX_BYTES = 65536;

    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly Http $request,
        private readonly FormRepositoryInterface $formRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly DraftStore $draftStore,
        private readonly FormAccess $formAccess,
        private readonly Session $customerSession,
        private readonly Config $config,
        private readonly Validator $formKeyValidator,
        private readonly JsonSerializer $json,
        private readonly LoggerInterface $logger,
        private readonly Impersonation $impersonation,
        private readonly Translator $translator,
        private readonly ScheduleGate $schedule
    ) {
    }

    public function execute(): Json
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        if (!$this->config->isDraftSyncEnabled($storeId)) {
            return $this->respond(404);
        }
        if (!$this->customerSession->isLoggedIn()) {
            return $this->respond(403, ['message' => (string)__('Please sign in to sync drafts.')]);
        }

        $formId = (int)$this->request->getParam('form_id');
        try {
            $form = $this->formRepository->getActiveById($formId, $storeId);
        } catch (NoSuchEntityException) {
            return $this->respond(404);
        }
        $settings = $form->getSettings();
        if (($settings['draft_mode'] ?? 'session') !== 'local') {
            return $this->respond(404);
        }
        if ($this->formAccess->check($settings, true, (int)$this->customerSession->getCustomerGroupId()) !== null) {
            return $this->respond(403, ['message' => (string)__('You are not allowed to submit this form.')]);
        }

        $customerId = (int)$this->customerSession->getCustomerId();
        if (!$this->request->isPost()) {
            return $this->respond(200, $this->draftStore->find($formId, $customerId, $storeId)
                ?? ['values' => null, 'updatedAt' => null]);
        }
        if ($this->impersonation->isActive()) {
            return $this->respond(403, ['message' => $this->impersonation->accountRefusal()]);
        }

        if ($this->request->getParam('action') === 'clear') {
            return $this->clear($formId, $customerId, $storeId);
        }
        $closed = $this->schedule->refusal(
            $form,
            fn (FormInterface $raw): FormInterface => $this->translator->localize($raw, $storeId)
        );

        return $closed !== null
            ? $this->respond(403, ['message' => $closed['message'], 'schedule' => $closed])
            : $this->save($form->getSchema(), $formId, $customerId, $storeId);
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // Loading is a GET; only saves need the form key (and XHR must not skip it).
        return $request instanceof Http && (!$request->isPost() || $this->formKeyValidator->validate($request));
    }

    private function csrfRefusal(string $message): Json
    {
        return $this->respond(403, ['message' => $message]);
    }

    private function clear(int $formId, int $customerId, int $storeId): Json
    {
        $this->draftStore->clear($formId, $customerId, $storeId);

        return $this->respond(200, ['success' => true]);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function save(array $schema, int $formId, int $customerId, int $storeId): Json
    {
        $raw = (string)$this->request->getParam('values');
        if (strlen($raw) > self::MAX_BYTES) {
            return $this->respond(413, ['message' => (string)__('This draft is too large to save.')]);
        }
        try {
            $posted = $this->json->unserialize($raw);
            if (!is_array($posted)) {
                return $this->respond(400, ['message' => (string)__('Invalid request.')]);
            }
            $values = $this->draftStore->sanitize($schema, $posted);
            $this->draftStore->save($formId, $customerId, $storeId, $values);
        } catch (\InvalidArgumentException) {
            return $this->respond(400, ['message' => (string)__('Invalid request.')]);
        } catch (\Throwable $e) {
            $this->logger->error('Form Builder draft save failed: ' . $e->getMessage(), ['exception' => $e]);
            return $this->respond(500, ['message' => (string)__('Something went wrong. Please try again later.')]);
        }

        return $this->respond(200, ['success' => true]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function respond(int $code, array $data = []): Json
    {
        $json = $this->noStore($this->jsonFactory->create());
        if ($code !== 200) {
            $json->setHttpResponseCode($code);
        }

        return $json->setData($data);
    }
}
