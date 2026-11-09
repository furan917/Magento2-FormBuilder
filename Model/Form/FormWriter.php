<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Form;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormManagementInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Api\FormWriteContext;
use Furan\FormBuilder\Model\FormFactory;
use Furan\FormBuilder\Model\I18n\Translator;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\Normalizer;
use Furan\FormBuilder\Model\Schema\SchemaMigrator;
use Furan\FormBuilder\Model\Schema\SettingsNormalizer;
use Furan\FormBuilder\Model\Schema\UnsupportedSchemaVersionException;
use Furan\FormBuilder\Model\Settings\ProtectedSettings;
use Furan\FormBuilder\Model\Webhook\WebhookAccess;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * @phpstan-import-type FormSchema from FormInterface
 * @phpstan-import-type FormSettings from FormInterface
 */
class FormWriter implements FormManagementInterface
{
    public function __construct(
        private readonly FormRepositoryInterface $formRepository,
        private readonly FormFactory $formFactory,
        private readonly FormResource $formResource,
        private readonly Normalizer $normalizer,
        private readonly SettingsNormalizer $settingsNormalizer,
        private readonly ProtectedSettings $protectedSettings,
        private readonly SchemaMigrator $schemaMigrator,
        private readonly Translator $translator,
        private readonly ScheduleTimezone $scheduleTimezone,
        private readonly PersonalUnticks $personalUnticks,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function save(
        ?FormInterface $existing,
        array $definition,
        FormWriteContext $context,
        ?FormInterface $baseline = null
    ): FormInterface {
        $baseline ??= $existing;
        if ($baseline !== null && !$this->schemaMigrator->isSupported($baseline->getSchema())) {
            $version = $this->schemaMigrator->versionOf($baseline->getSchema());
            throw new UnsupportedSchemaVersionException($version, (string)__(
                'This form was saved by a newer version of the module (schema version %1) and cannot be changed here.',
                $version
            ));
        }

        $errors = [];
        $identifier = $this->identifier($definition['identifier'] ?? null, $existing, $errors);
        $title = $this->title($definition['title'] ?? null, $errors);
        $stores = $this->storeManager->getStores(true);
        $storeIds = $this->storeIds($definition['store_ids'] ?? [], array_map('intval', array_keys($stores)), $errors);
        $rawSchema = $definition['schema'] ?? null;
        $rawSettings = $definition['settings'] ?? null;
        $content = null;
        if (is_array($rawSchema) && is_array($rawSettings)) {
            $content = $this->content($rawSchema, $rawSettings, $existing, $baseline, $context, $storeIds, $errors);
        } else {
            $errors[] = (string)__('The form data could not be read.');
        }
        if ($errors !== [] || $content === null) {
            throw new InvalidSchemaException($errors);
        }
        [$schema, $settings] = $content;

        $form = $existing ?? $this->formFactory->create();
        $form->setIdentifier($identifier)
            ->setTitle($title)
            ->setIsActive(filter_var($definition['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN))
            ->setStoreIds($storeIds)
            ->setSchema($schema)
            ->setSettings($settings);
        if (array_key_exists('translations', $definition)) {
            $translatable = array_values(array_diff(array_map('intval', array_keys($stores)), [0]));
            $form->setTranslations($this->translator->normalize($definition['translations'], $schema, $settings, $translatable));
        }
        return $this->formRepository->save($form);
    }

    /** @param string[] $errors */
    private function identifier(mixed $raw, ?FormInterface $existing, array &$errors): string
    {
        $identifier = is_string($raw) ? $raw : '';
        if (!Identifier::isValid($identifier)) {
            $errors[] = (string)__('The identifier may only contain lowercase letters, numbers and hyphens (max 64).');
        } else {
            $usedBy = $this->formResource->getIdByIdentifier($identifier);
            if ($usedBy !== null && $usedBy !== (int)$existing?->getId()) {
                $errors[] = (string)__('The identifier "%1" is already used.', $identifier);
            }
        }
        return $identifier;
    }

    /** @param string[] $errors */
    private function title(mixed $raw, array &$errors): string
    {
        $title = trim(is_string($raw) ? $raw : '');
        if (!Identifier::isValidTitle($title)) {
            $errors[] = (string)__('The title must be between 1 and %1 characters.', Identifier::MAX_TITLE_LENGTH);
        }
        return $title;
    }

    /**
     * @param int[] $known
     * @param string[] $errors
     * @return int[]
     */
    private function storeIds(mixed $raw, array $known, array &$errors): array
    {
        if (!is_array($raw)) {
            $errors[] = (string)__('The form data could not be read.');
            return [0];
        }
        $ids = [];
        foreach ($raw as $value) {
            $isInteger = is_int($value) || (is_string($value) && ctype_digit($value));
            $id = $isInteger ? (int)$value : -1;
            if ($id < 0 || ($id !== 0 && !in_array($id, $known, true))) {
                $errors[] = (string)__('Store view %1 does not exist.', is_scalar($value) ? (string)$value : (string)json_encode($value));
                continue;
            }
            $ids[$id] = $id;
        }
        return $ids === [] ? [0] : array_values($ids);
    }

    /**
     * @param array<string, mixed> $rawSchema
     * @param array<string, mixed> $rawSettings
     * @param int[] $storeIds
     * @param string[] $errors
     * @return array{0: FormSchema, 1: FormSettings}|null
     */
    private function content(
        array $rawSchema,
        array $rawSettings,
        ?FormInterface $existing,
        ?FormInterface $baseline,
        FormWriteContext $context,
        array $storeIds,
        array &$errors
    ): ?array {
        $stored = $baseline?->getSettings() ?? [];
        $settings = $this->protectedSettings->restrict($rawSettings, $stored, $context->isAllowed(...));
        try {
            $schema = $this->normalizer->normalize($this->schemaMigrator->upgrade($rawSchema), $baseline?->getSchema());
            array_push($errors, ...$this->personalUntickErrors($existing, $schema, $context));
            if (!$context->isAllowed(ProtectedSettings::SUBMISSIONS_RESOURCE)) {
                $settings = $this->settingsNormalizer->pruneRouting($settings, $schema);
            }
            $settings = $this->settingsNormalizer->normalize(
                $settings,
                $schema,
                $stored,
                $this->scheduleTimezone->defaultFor($storeIds)
            );
        } catch (InvalidSchemaException $e) {
            array_push($errors, ...$e->getErrors());
            return null;
        }
        return [$schema, $settings];
    }

    /**
     * @param array<string, mixed> $schema
     * @return string[]
     */
    private function personalUntickErrors(?FormInterface $existing, array $schema, FormWriteContext $context): array
    {
        if ($existing === null) {
            return [];
        }
        $unticked = $this->personalUnticks->find($existing->getSchema(), $schema);
        if ($unticked === []) {
            return [];
        }
        if (!$context->isAllowed(WebhookAccess::PERSONAL)) {
            return [(string)__(
                'Only an admin allowed to send personal data in webhooks can mark these fields as not personal: %1.',
                implode(', ', array_column($unticked, 'label'))
            )];
        }
        $this->logger->info('Personal flag removed from form fields.', [
            'admin_id' => $context->adminId,
            'form_id' => (int)$existing->getId(),
            'fields' => array_column($unticked, 'name'),
        ]);
        return [];
    }
}
