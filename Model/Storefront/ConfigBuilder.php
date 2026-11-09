<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Storefront;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FieldType\StorefrontConfigInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Schema\PublicSchema;
use Furan\FormBuilder\Model\Schema\SchemaFields;
use Furan\FormBuilder\Model\Spam\ReCaptcha;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/** The storefront config of a form; it reads per-form, per-store and per-URL inputs only, never visitor data. */
class ConfigBuilder
{
    public function __construct(
        private readonly PublicSchema $publicSchema,
        private readonly Pool $fieldTypes,
        private readonly Config $moduleConfig,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly ReCaptcha $reCaptcha,
        private readonly Eagerness $eagerness,
        private readonly ManagerInterface $eventManager
    ) {
    }

    /** The product id a request carries, or null when it is not a plain positive id. */
    public static function productId(mixed $raw): ?int
    {
        $id = is_scalar($raw) && preg_match('/^[1-9]\d{0,9}$/D', (string)$raw) ? (int)$raw : 0;
        return $id > 0 ? $id : null;
    }

    /**
     * @param array<string, mixed> $visible the form schema without unavailable field types
     * @param \Closure(string, array<string, mixed>=): string $url storefront url of a route and its query
     * @param \Closure(string): string $assetUrl url of a view file id
     * @return array<string, mixed>
     */
    public function build(
        FormInterface $form,
        array $visible,
        int $storeId,
        ?int $productId,
        bool $firstInstance,
        \Closure $url,
        \Closure $assetUrl
    ): array {
        $settings = $form->getSettings();
        $formId = (int)$form->getId();
        $query = ['form_id' => $formId];

        $config = [
            'formId' => $formId,
            'submitUrl' => $url('furanform/form/submit'),
            'tokenUrl' => $url('furanform/form/token', $query),
            'draft' => [
                // Only the first instance of a form on a page may save or restore the shared draft.
                'mode' => $firstInstance ? (string)($settings['draft_mode'] ?? 'session') : 'off',
                'key' => sprintf('ffb:%d:%d:%s', $storeId, $formId, $this->draftFingerprint($form->getSchema())),
                'ttlHours' => $this->moduleConfig->getDraftTtlHours($storeId),
            ],
            'schema' => $this->publicSchema->build($visible),
            'uploads' => ['maxMb' => $this->moduleConfig->getMaxUploadMb($storeId)],
            'messages' => [
                'required' => (string)__('This field is required.'),
                'network' => (string)__('We could not send the form. Please check your connection and try again.'),
                'generic' => (string)__('Something went wrong. Please try again.'),
                'sending' => (string)__('Sending…'),
                'waiting' => (string)__('Please wait a moment…'),
                'restored' => (string)__('We restored your unsent answers.'),
                'startOver' => (string)__('Start over'),
                'stepOf' => (string)__('Step %1 of %2', '%1', '%2'),
                'fixErrors' => (string)__('Please fix the highlighted fields.'),
                'fileTooLarge' => (string)__('The file is too large (maximum %1 MB).', '%1'),
                'fileType' => (string)__('This file type is not allowed (%1).', '%1'),
                'recaptchaRequired' => (string)__('Please confirm you are not a robot.'),
                'gateLogin' => (string)__('Please sign in to use this form.'),
                'gateGroup' => (string)__('This form is not available for your account.'),
                'gateAnswered' => (string)__('You have already answered this form.'),
                'editing' => (string)__('You are changing your earlier answer. Sending replaces it.'),
                'editInvalid' => (string)__('This edit link is no longer valid. Sending creates a new answer.'),
                'editSignIn' => (string)__('Please sign in to change your earlier answer.'),
                'gateLoginLink' => (string)__('Sign in'),
                'gateRegisterLink' => (string)__('Create an account'),
                'reference' => (string)__('Your reference'),
                'copy' => (string)__('Copy'),
                'copied' => (string)__('Copied'),
            ],
            // A reference nobody can look up is noise, so forms that store nothing never show it.
            'showReference' => (bool)($settings['show_reference'] ?? true) && (bool)($settings['store_submissions'] ?? true),
            'context' => [
                'eager' => $this->eagerness->isEager($settings, $visible, $productId),
                'productId' => $productId,
                'loginUrl' => $url('furanform/account/login'),
            ],
            'labels' => [
                'submit' => $this->label($settings['submit_label'] ?? '', 'Submit'),
                'next' => $this->label($settings['next_label'] ?? '', 'Next'),
                'prev' => $this->label($settings['prev_label'] ?? '', 'Back'),
            ],
        ];

        $config += $this->typeConfig($visible, $storeId, array_keys($config));

        if ($config['draft']['mode'] === 'local' && $this->moduleConfig->isDraftSyncEnabled($storeId)) {
            $config['draft']['serverUrl'] = $url('furanform/form/draft', $query);
        }

        $recaptcha = $this->reCaptcha->getUiConfig();
        if ($recaptcha !== null) {
            $config['recaptcha'] = ['scriptUrl' => $assetUrl('Furan_FormBuilder::js/ffb-recaptcha.js')] + $recaptcha;
        }
        return $this->configAfterObservers($form, $storeId, $config);
    }

    /**
     * Only what makes a saved draft incompatible: labels and help can change without discarding visitors' drafts.
     *
     * @param array<string, mixed> $schema
     */
    private function draftFingerprint(array $schema): string
    {
        $pairs = [];
        foreach (SchemaFields::all($schema) as $field) {
            if (($field['draft'] ?? true) !== false) {
                $pairs[] = ($field['name'] ?? '') . ':' . ($field['type'] ?? '');
            }
        }
        return substr(hash('sha256', (string)$this->json->serialize($pairs)), 0, 12);
    }

    /**
     * HTML is cached per form and store: observers add per-form or per-store data, never per-visitor.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function configAfterObservers(FormInterface $form, int $storeId, array $config): array
    {
        $protected = array_intersect_key($config, array_flip(['formId', 'submitUrl', 'tokenUrl', 'schema']));
        $object = new DataObject($config);
        $this->eventManager->dispatch(
            'furan_formbuilder_storefront_config_build',
            ['form' => $form, 'store_id' => $storeId, 'config' => $object]
        );

        return array_replace($object->getData(), $protected);
    }

    /**
     * Never overrides existing keys or `recaptcha`, which is added afterwards.
     *
     * @param array<string, mixed> $schema
     * @param string[] $taken
     * @return array<string, mixed>
     */
    private function typeConfig(array $schema, int $storeId, array $taken): array
    {
        $taken[] = 'recaptcha';
        $config = [];
        foreach ($this->fieldsByType($schema) as $code => $fields) {
            $type = $this->fieldTypes->has($code) ? $this->fieldTypes->get($code) : null;
            if (!$type instanceof StorefrontConfigInterface) {
                continue;
            }
            foreach ($type->storefrontConfig($fields, $storeId) as $key => $value) {
                if (in_array($key, $taken, true) || array_key_exists($key, $config)) {
                    $this->logger->warning(sprintf('Field type "%s" storefront config key "%s" ignored.', $code, $key));
                    continue;
                }
                $config[$key] = $value;
            }
        }
        return $config;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, list<array<string, mixed>>> schema fields keyed by type code, in field type pool order
     */
    private function fieldsByType(array $schema): array
    {
        $found = [];
        foreach (SchemaFields::all($schema) as $field) {
            $found[(string)($field['type'] ?? '')][] = $field;
        }
        $ordered = [];
        foreach (array_keys($this->fieldTypes->all()) as $code) {
            if (isset($found[$code])) {
                $ordered[$code] = $found[$code];
            }
        }
        return $ordered;
    }

    private function label(mixed $configured, string $default): string
    {
        $configured = trim((string)$configured);
        return $configured !== '' ? $configured : (string)__($default);
    }
}
