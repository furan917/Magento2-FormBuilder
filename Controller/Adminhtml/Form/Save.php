<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Controller\Adminhtml\Form;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormManagementInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Adminhtml\ReturnsRedirect;
use Furan\FormBuilder\Model\Access\FormAccess;
use Furan\FormBuilder\Model\Admin\PersistKeys;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\UnsupportedSchemaVersionException;
use Furan\FormBuilder\Model\Spam\ReCaptcha;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Save extends AbstractForm implements HttpPostActionInterface
{
    use ReturnsRedirect;

    public const ADMIN_RESOURCE = 'Furan_FormBuilder::forms_save';

    private const FIELDS = [
        'form_id', 'identifier', 'title', 'is_active', 'store_ids', 'schema', 'settings', 'translations', 'back',
    ];

    public function __construct(
        Context $context,
        private readonly FormRepositoryInterface $formRepository,
        private readonly FormManagementInterface $formManagement,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly ReCaptcha $reCaptcha,
        private readonly StoreManagerInterface $storeManager,
        private readonly FormAccess $formAccess
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $posted = [];
        foreach (self::FIELDS as $field) {
            $posted[$field] = (string)$this->getRequest()->getParam($field, '');
        }
        $formId = (int)$posted['form_id'];

        $form = null;
        if ($posted['form_id'] !== '') {
            try {
                $form = $this->formRepository->getById($formId);
            } catch (NoSuchEntityException) {
                $this->messageManager->addErrorMessage(__('This form no longer exists.'));
                return $this->redirect('*/*/');
            }
        }

        $errors = [];
        $definition = $this->definition($posted);
        if ($definition === null) {
            $errors[] = (string)__('The form data could not be read.');
        } else {
            try {
                $saved = $this->formManagement->save($form, $definition, $this->writeContext());
                $this->messageManager->addSuccessMessage(__('The form has been saved.'));
                $settings = $saved->getSettings();
                // Bots only reach forms that guests may fill in.
                if (!empty($settings['autoresponder_enabled']) && $this->formAccess->check($settings, false, 0) === null
                    && !$this->reCaptchaCoversStores($saved)
                ) {
                    $this->messageManager->addWarningMessage(__(
                        'This form sends a confirmation email to whatever address is typed in, so bots can use it to email strangers from your store. Turn on reCAPTCHA for Form Builder forms under Stores > Configuration > Security > Google reCAPTCHA Storefront.'
                    ));
                }
                return $posted['back'] === 'edit'
                    ? $this->redirect('*/*/edit', ['id' => $saved->getId()])
                    : $this->redirect('*/*/');
            } catch (UnsupportedSchemaVersionException $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
                return $this->redirect('*/*/edit', ['id' => $formId]);
            } catch (InvalidSchemaException $e) {
                $errors = $e->getErrors();
            } catch (LocalizedException $e) {
                $errors[] = $e->getMessage();
            } catch (\Throwable $e) {
                $this->logger->error($e->getMessage(), ['exception' => $e]);
                $errors[] = (string)__('Something went wrong while saving the form.');
            }
        }

        $this->dataPersistor->set(PersistKeys::FORM, $posted + ['errors' => $errors]);
        foreach ($errors as $error) {
            $this->messageManager->addErrorMessage($error);
        }
        return $formId > 0 ? $this->redirect('*/*/edit', ['id' => $formId]) : $this->redirect('*/*/new');
    }

    /** Each website of the form's store views; "all store views" means every website. */
    private function reCaptchaCoversStores(FormInterface $form): bool
    {
        $storeIds = array_map('intval', $form->getStoreIds());
        $websiteIds = [];
        if (in_array(0, $storeIds, true)) {
            foreach ($this->storeManager->getWebsites() as $website) {
                $websiteIds[] = (int)$website->getId();
            }
        } else {
            foreach ($storeIds as $storeId) {
                try {
                    $websiteIds[] = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
                } catch (NoSuchEntityException) {
                    continue;
                }
            }
        }
        foreach (array_unique($websiteIds) as $websiteId) {
            if (!$this->reCaptcha->isEnabledForWebsite($websiteId)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, string> $posted
     * @return array<string, mixed>|null
     */
    private function definition(array $posted): ?array
    {
        $storeIds = $this->decode($posted['store_ids']);
        $schema = $this->decode($posted['schema']);
        $settings = $this->decode($posted['settings']);
        $translations = $posted['translations'] === '' ? [] : $this->decode($posted['translations']);
        if ($storeIds === null || $schema === null || $settings === null || $translations === null) {
            return null;
        }
        $definition = [
            'identifier' => $posted['identifier'],
            'title' => $posted['title'],
            'is_active' => filter_var($posted['is_active'], FILTER_VALIDATE_BOOLEAN),
            'store_ids' => $storeIds,
            'schema' => $schema,
            'settings' => $settings,
        ];
        // FormManagementInterface::save() keeps the saved translations when the key is absent.
        if ($posted['translations'] !== '') {
            $definition['translations'] = $translations;
        }
        return $definition;
    }

    /**
     * @return array<mixed>|null
     */
    private function decode(string $raw): ?array
    {
        try {
            $decoded = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $params
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    private function redirect(string $path, array $params = [])
    {
        return $this->redirectResult()->setPath($path, $params);
    }
}
