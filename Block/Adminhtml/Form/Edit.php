<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Block\Adminhtml\Form;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Model\Admin\BuilderSharedConfig;
use Furan\FormBuilder\Model\Admin\PersistKeys;
use Furan\FormBuilder\Model\Admin\PreviewLink;
use Furan\FormBuilder\Model\Form\Schedule;
use Furan\FormBuilder\Model\Form\ScheduleTimezone;
use Furan\FormBuilder\Model\Schema\SchemaMigrator;
use Furan\FormBuilder\Model\Schema\SettingsNormalizer;
use Furan\FormBuilder\Model\Settings\ContributorPool;
use Furan\FormBuilder\Model\Settings\ProtectedSettings;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Serialize\Serializer\JsonHexTag;

class Edit extends Template
{
    private const ACL_FIELD_GROUPS = 'Furan_FormBuilder::field_groups';
    private const ACL_FIELD_GROUPS_EDIT = 'Furan_FormBuilder::field_groups_edit';

    /** @var array<string, mixed>|null */
    private ?array $config = null;

    /** @param array<string, mixed> $data */
    public function __construct(
        Context $context,
        private readonly BuilderSharedConfig $sharedConfig,
        private readonly FormRepositoryInterface $formRepository,
        private readonly SettingsNormalizer $settingsNormalizer,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly PreviewLink $previewLink,
        private readonly Json $json,
        private readonly JsonHexTag $jsonHexTag,
        private readonly ContributorPool $contributors,
        private readonly SchemaMigrator $schemaMigrator,
        private readonly ScheduleTimezone $scheduleTimezone,
        private readonly ProtectedSettings $protectedSettings,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @return array<string, mixed> */
    public function getBuilderConfig(): array
    {
        if ($this->config === null) {
            $persisted = $this->takePersisted();
            $form = $this->buildFormData($persisted);
            $newerSchema = $this->newerSchemaVersion();
            $this->config = ['form' => $form] + $this->sharedConfig->get() + [
                'settingsContributors' => $this->settingsContributors(),
                'schedule' => $this->schedule($form),
                'urls' => [
                    'save' => $this->getSaveUrl(),
                    'back' => $this->getUrl('*/*/index'),
                    'preview' => $this->previewUrl($form),
                    'rules' => $this->_authorization->isAllowed('Furan_FormBuilder::rules') ? $this->getUrl('*/rule/index') : '',
                    'duplicate' => $form['id'] !== null ? $this->getUrl('*/*/duplicate', ['id' => $form['id']]) : '',
                    'delete' => $form['id'] !== null && $this->_authorization->isAllowed('Furan_FormBuilder::forms_delete')
                        ? $this->getUrl('*/*/delete')
                        : '',
                ],
                'fieldGroups' => $this->fieldGroupUrls(),
                'permissions' => [
                    'save' => $newerSchema === null && $this->_authorization->isAllowed('Furan_FormBuilder::forms_save'),
                    'notifications' => $this->_authorization->isAllowed('Furan_FormBuilder::submissions'),
                    'field_groups' => $this->_authorization->isAllowed(self::ACL_FIELD_GROUPS),
                    'field_groups_edit' => $this->_authorization->isAllowed(self::ACL_FIELD_GROUPS_EDIT),
                ],
                'errors' => array_merge($newerSchema === null ? [] : [$this->newerSchemaMessage($newerSchema)], $this->errors($persisted)),
            ];
        }
        return $this->config;
    }

    public function getConfigJson(): string
    {
        return $this->jsonHexTag->serialize($this->getBuilderConfig());
    }

    public function getFormId(): string
    {
        $id = $this->getBuilderConfig()['form']['id'];
        return $id === null ? '' : (string)$id;
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('*/*/save');
    }

    /** @return array<string, mixed> */
    private function takePersisted(): array
    {
        $persisted = $this->dataPersistor->get(PersistKeys::FORM);
        if (!is_array($persisted) || $persisted === []) {
            return [];
        }
        $this->dataPersistor->clear(PersistKeys::FORM);
        return $persisted;
    }

    /**
     * @param array<string, mixed> $persisted
     * @return array<string, mixed>
     */
    private function buildFormData(array $persisted): array
    {
        $saved = $this->loadSavedForm();
        if ($saved !== null) {
            $form = [
                'id' => (int)$saved->getId(),
                'identifier' => $saved->getIdentifier(),
                'title' => $saved->getTitle(),
                'is_active' => $saved->isActive(),
                'store_ids' => $saved->getStoreIds(),
                'schema' => $saved->getSchema(),
                'settings' => $saved->getSettings(),
                'translations' => $saved->getTranslations(),
            ];
        } else {
            $schema = [
                'version' => 1,
                'pages' => [['id' => 'p_page01', 'title' => (string)__('Page %1', 1), 'condition' => null, 'items' => []]],
            ];
            $form = [
                'id' => null,
                'identifier' => '',
                'title' => '',
                'is_active' => true,
                'store_ids' => [0],
                'schema' => $schema,
                'settings' => $this->settingsNormalizer->normalize([], $schema),
                'translations' => [],
            ];
        }
        $form = $persisted === [] ? $form : $this->applyPersisted($form, $persisted);
        if (is_array($form['settings'] ?? null)) {
            $form['settings'] = array_diff_key(
                $form['settings'],
                array_flip($this->protectedSettings->hiddenKeys($this->_authorization->isAllowed(...)))
            );
        }

        return $form;
    }

    /** The saved form's schema version when this module is too old to edit it, else null. */
    private function newerSchemaVersion(): ?int
    {
        $schema = $this->loadSavedForm()?->getSchema();
        return $schema === null || $this->schemaMigrator->isSupported($schema) ? null : $this->schemaMigrator->versionOf($schema);
    }

    private function newerSchemaMessage(int $version): string
    {
        return (string)__('This form was saved by a newer version of the module (schema version %1) and cannot be changed here.', $version);
    }

    private function loadSavedForm(): ?FormInterface
    {
        $id = (int)$this->getRequest()->getParam('id');
        if ($id <= 0) {
            return null;
        }
        try {
            return $this->formRepository->getById($id);
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $persisted
     * @return array<string, mixed>
     */
    private function applyPersisted(array $form, array $persisted): array
    {
        foreach (['identifier', 'title'] as $key) {
            if (isset($persisted[$key]) && is_string($persisted[$key])) {
                $form[$key] = $persisted[$key];
            }
        }
        if (isset($persisted['is_active'])) {
            $form['is_active'] = filter_var($persisted['is_active'], FILTER_VALIDATE_BOOLEAN);
        }
        foreach (['store_ids', 'schema', 'settings', 'translations'] as $key) {
            $decoded = $this->decode($persisted[$key] ?? null);
            if ($decoded !== null) {
                $form[$key] = $decoded;
            }
        }
        return $form;
    }

    /** @return array<mixed>|null */
    private function decode(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $decoded = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $persisted
     * @return list<string>
     */
    private function errors(array $persisted): array
    {
        $errors = $persisted['errors'] ?? [];
        return is_array($errors) ? array_values(array_map('strval', $errors)) : [];
    }

    /**
     * Endpoint URLs for the group actions this admin may use; nothing is exposed otherwise.
     *
     * @return array<string, string>
     */
    private function fieldGroupUrls(): array
    {
        $urls = [];
        $mayView = $this->_authorization->isAllowed(self::ACL_FIELD_GROUPS);
        if ($mayView) {
            $urls['list'] = $this->getUrl('*/fieldgroup/palette');
            $urls['insert'] = $this->getUrl('*/fieldgroup/insert');
        }
        if ($mayView && $this->_authorization->isAllowed(self::ACL_FIELD_GROUPS_EDIT)) {
            $urls['create'] = $this->getUrl('*/fieldgroup/create');
        }
        return $urls;
    }

    /** @return list<array<string, mixed>> */
    private function settingsContributors(): array
    {
        $panels = [];
        foreach ($this->contributors->all() as $contributor) {
            $resource = $contributor->getAclResource();
            $panels[] = [
                'code' => $contributor->getCode(),
                'label' => (string)$contributor->getLabel(),
                'spec' => $contributor->getSpec(),
                'readOnly' => $resource !== null && !$this->_authorization->isAllowed($resource),
            ];
        }
        return $panels;
    }

    /**
     * `local` = the stored UTC instants as wall-clock time in the form's own zone (the builder posts the
     * stored instant back unless the admin edits it); `storeTimezones` and `defaultTimezone` only pre-select
     * the zone of a form that has none yet.
     *
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private function schedule(array $form): array
    {
        $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
        $storeZones = $this->scheduleTimezone->storeZones();
        $stored = is_string($settings['schedule_timezone'] ?? null) ? $settings['schedule_timezone'] : '';
        $zone = $stored !== '' ? $stored : $this->scheduleTimezone->defaultFor(array_map('intval', (array)($form['store_ids'] ?? [])));
        $local = [];
        foreach (Schedule::KEYS as $key) {
            $local[$key] = Schedule::toLocal($settings[$key] ?? '', $zone);
        }
        return [
            'timezones' => $this->scheduleTimezone->options(
                array_merge([$stored, $this->scheduleTimezone->defaultScope()], array_values($storeZones))
            ),
            'storeTimezones' => $storeZones,
            'defaultTimezone' => $this->scheduleTimezone->defaultScope(),
            'local' => $local,
        ];
    }

    /** @param array<string, mixed> $form */
    private function previewUrl(array $form): ?string
    {
        if ($form['id'] === null || $form['identifier'] === '') {
            return null;
        }
        $storeIds = array_map('intval', $form['store_ids']);
        $storeId = $storeIds === [] || in_array(0, $storeIds, true)
            ? (int)$this->_storeManager->getDefaultStoreView()?->getId()
            : $storeIds[0];
        try {
            return $this->previewLink->url((int)$form['id'], (string)$form['identifier'], (bool)$form['is_active'], $storeId);
        } catch (NoSuchEntityException) {
            return null;
        }
    }
}
