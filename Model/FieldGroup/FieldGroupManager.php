<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldGroup;

use Furan\FormBuilder\Model\FieldGroup;
use Furan\FormBuilder\Model\FieldGroupFactory;
use Furan\FormBuilder\Model\ResourceModel\FieldGroup as FieldGroupResource;
use Furan\FormBuilder\Model\ResourceModel\FieldGroup\CollectionFactory;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Admin-side lifecycle of reusable field groups. A group is a copy of fields: forms keep no link to it,
 * so deleting one never touches a form.
 */
class FieldGroupManager
{
    public const MAX_NAME_LENGTH = 120;
    public const MAX_DESCRIPTION_LENGTH = 500;
    private const MAX_JSON_BYTES = 4000000;

    public function __construct(
        private readonly FieldGroupFactory $groupFactory,
        private readonly FieldGroupResource $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly GroupNormalizer $normalizer,
        private readonly StoreManagerInterface $storeManager,
        private readonly Json $json
    ) {
    }

    /**
     * @throws NoSuchEntityException
     */
    public function get(int $id): FieldGroup
    {
        $group = $this->groupFactory->create();
        $this->resource->load($group, $id);
        if (!$group->getId()) {
            throw new NoSuchEntityException(__('This field group no longer exists.'));
        }
        return $group;
    }

    /**
     * Without `fields` an existing group keeps its stored fields and translations.
     *
     * @param array<string, mixed> $data name, description, fields (list of items), translations (store id => overlay)
     * @throws LocalizedException listing every problem, one per line
     * @throws NoSuchEntityException
     */
    public function save(array $data, ?int $id = null): FieldGroup
    {
        $group = $id ? $this->get($id) : $this->groupFactory->create();
        $name = $this->cleanLine($data['name'] ?? '', self::MAX_NAME_LENGTH);
        $errors = [];
        if ($name === '') {
            $errors[] = (string)__('Enter a name.');
        } elseif ($this->nameTaken($name, (int)$group->getId())) {
            $errors[] = $this->nameTakenError($name);
        }
        $stored = [];
        if (!$group->getId() || array_key_exists('fields', $data)) {
            $stored = $this->normalizeContent($data, $errors);
        }
        if ($errors) {
            throw new LocalizedException(__(implode("\n", $errors)));
        }
        $group->addData([
            'name' => $name,
            'description' => $this->cleanText($data['description'] ?? '', self::MAX_DESCRIPTION_LENGTH),
        ] + $stored);
        try {
            $this->resource->save($group);
        } catch (AlreadyExistsException) {
            throw new LocalizedException(__($this->nameTakenError($name)));
        }
        return $group;
    }

    /**
     * @throws NoSuchEntityException
     */
    public function delete(int $id): void
    {
        $this->resource->delete($this->get($id));
    }

    /**
     * @return list<mixed>
     */
    public function fieldsOf(FieldGroup $group): array
    {
        return array_values($this->decode((string)$group->getData('fields_json')));
    }

    /**
     * @return array<int, array<string, mixed>> store id => overlay
     */
    public function translationsOf(FieldGroup $group): array
    {
        return $this->decode((string)$group->getData('translations_json'));
    }

    /**
     * @param array<string, mixed> $data
     * @param string[] $errors
     * @return array{fields_json?: string, translations_json?: string}
     */
    private function normalizeContent(array $data, array &$errors): array
    {
        try {
            $clean = $this->normalizer->normalize(
                $data['fields'] ?? null,
                $data['translations'] ?? [],
                array_map('intval', array_keys($this->storeManager->getStores()))
            );
        } catch (InvalidSchemaException $e) {
            array_push($errors, ...$e->getErrors());
            return [];
        }
        $fields = (string)$this->json->serialize($clean['fields']);
        $translations = (string)$this->json->serialize($clean['translations']);
        if (strlen($fields) > self::MAX_JSON_BYTES || strlen($translations) > self::MAX_JSON_BYTES) {
            $errors[] = (string)__('This group is too large to save.');
            return [];
        }
        return ['fields_json' => $fields, 'translations_json' => $translations];
    }

    private function nameTaken(string $name, int $exceptId): bool
    {
        $collection = $this->collectionFactory->create()->addFieldToFilter('name', $name);
        if ($exceptId > 0) {
            $collection->addFieldToFilter('group_id', ['neq' => $exceptId]);
        }
        return $collection->getSize() > 0;
    }

    private function nameTakenError(string $name): string
    {
        return (string)__('A field group named "%1" already exists.', $name);
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $json): array
    {
        try {
            $decoded = $json === '' ? [] : $this->json->unserialize($json);
        } catch (\InvalidArgumentException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function cleanLine(mixed $value, int $max): string
    {
        $value = is_scalar($value) ? preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', (string)$value) ?? '' : '';
        return mb_substr(trim($value), 0, $max);
    }

    private function cleanText(mixed $value, int $max): string
    {
        $value = is_scalar($value) ? preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', (string)$value) ?? '' : '';
        return mb_substr(trim($value), 0, $max);
    }
}
