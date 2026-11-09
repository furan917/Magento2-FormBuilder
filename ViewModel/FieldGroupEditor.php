<?php
declare(strict_types=1);

namespace Furan\FormBuilder\ViewModel;

use Furan\FormBuilder\Model\Admin\PersistKeys;
use Furan\FormBuilder\Model\FieldGroup;
use Furan\FormBuilder\Model\FieldGroup\FieldGroupManager;
use Furan\FormBuilder\Model\FieldGroup\GroupUsage;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * The field group page: the group as a one-page form for the builder, and the forms that contain it.
 */
class FieldGroupEditor implements ArgumentInterface
{
    public const PAGE_ID = 'p_group000';

    /** @var array<string, mixed>|null */
    private ?array $form = null;

    public function __construct(
        private readonly FieldGroupManager $manager,
        private readonly GroupUsage $usage,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly AuthorizationInterface $authorization,
        private readonly UrlInterface $url,
        private readonly RequestInterface $request,
        private readonly Json $json
    ) {
    }

    public function getGroupId(): int
    {
        return (int)$this->request->getParam('id');
    }

    /**
     * A failed save's input, else the stored group.
     *
     * @return array<string, mixed>
     */
    public function getForm(): array
    {
        if ($this->form !== null) {
            return $this->form;
        }
        $group = $this->group();
        $values = [
            'name' => $group ? (string)$group->getData('name') : '',
            'description' => $group ? (string)$group->getData('description') : '',
            'fields' => $group ? $this->manager->fieldsOf($group) : [],
            'translations' => $group ? $this->manager->translationsOf($group) : [],
        ];
        $persisted = $this->dataPersistor->get(PersistKeys::FIELD_GROUP);
        if (is_array($persisted) && $persisted !== []) {
            $this->dataPersistor->clear(PersistKeys::FIELD_GROUP);
            $values = $this->applyPersisted($values, $persisted);
        }
        return $this->form = [
            'id' => $this->getGroupId(),
            'identifier' => '',
            'title' => $values['name'],
            'description' => $values['description'],
            'is_active' => true,
            'store_ids' => [0],
            'schema' => ['version' => 1, 'pages' => [['id' => self::PAGE_ID, 'title' => '', 'condition' => null, 'items' => $values['fields']]]],
            'settings' => [],
            'translations' => $values['translations'],
        ];
    }

    public function canEdit(): bool
    {
        return $this->authorization->isAllowed('Furan_FormBuilder::field_groups_edit');
    }

    public function canDelete(): bool
    {
        return $this->authorization->isAllowed('Furan_FormBuilder::field_groups_delete');
    }

    /**
     * @return array{rows: list<array{title: string, url: string}>, total: int}
     */
    public function getUsage(): array
    {
        $usage = $this->usage->formsUsing($this->getGroupId());
        $canOpen = $this->authorization->isAllowed('Furan_FormBuilder::forms');
        $rows = array_map(fn (array $row): array => [
            'title' => $row['title'],
            'url' => $canOpen ? $this->url->getUrl('furanform/form/edit', ['id' => $row['id']]) : '',
        ], $usage['rows']);
        return ['rows' => $rows, 'total' => $usage['total']];
    }

    /**
     * Group endpoints the builder may call here; saving a group from inside a group is left out.
     *
     * @return array<string, string>
     */
    public function getFieldGroupUrls(): array
    {
        return $this->canEdit()
            ? ['list' => $this->url->getUrl('furanform/fieldgroup/palette'), 'insert' => $this->url->getUrl('furanform/fieldgroup/insert')]
            : [];
    }

    public function getSaveUrl(): string
    {
        return $this->url->getUrl('furanform/fieldgroup/save');
    }

    public function getDeleteUrl(): string
    {
        return $this->url->getUrl('furanform/fieldgroup/delete');
    }

    public function getBackUrl(): string
    {
        return $this->url->getUrl('furanform/fieldgroup/index');
    }

    private function group(): ?FieldGroup
    {
        try {
            return $this->manager->get($this->getGroupId());
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /**
     * @param array{name: string, description: string, fields: list<mixed>, translations: array<mixed>} $values
     * @param array<mixed> $persisted
     * @return array{name: string, description: string, fields: list<mixed>, translations: array<mixed>}
     */
    private function applyPersisted(array $values, array $persisted): array
    {
        foreach (['name', 'description'] as $key) {
            if (is_string($persisted[$key] ?? null)) {
                $values[$key] = $persisted[$key];
            }
        }
        $fields = $this->decode($persisted['fields'] ?? null);
        if ($fields !== null && array_is_list($fields)) {
            $values['fields'] = $fields;
        }
        $translations = $this->decode($persisted['translations'] ?? null);
        if ($translations !== null) {
            $values['translations'] = $translations;
        }
        return $values;
    }

    /** @return array<mixed>|null */
    private function decode(mixed $raw): ?array
    {
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
}
