<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Draft;

use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Schema\SchemaFields;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;

class DraftStore
{
    private const TABLE = 'furan_formbuilder_draft';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Json $json,
        private readonly Pool $fieldTypes
    ) {
    }

    /**
     * Keeps only values of current, draftable, unlocked schema fields, shallow shapes only.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function sanitize(array $schema, array $values): array
    {
        $clean = [];
        foreach ($this->draftableNames($schema) as $name) {
            if (array_key_exists($name, $values) && $this->isShallow($values[$name])) {
                $clean[$name] = $values[$name];
            }
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function save(int $formId, int $customerId, int $storeId, array $values): void
    {
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            [
                'form_id' => $formId,
                'customer_id' => $customerId,
                'store_id' => $storeId,
                'payload_json' => $this->json->serialize($values),
                'updated_at' => new \Zend_Db_Expr('CURRENT_TIMESTAMP'),
            ],
            ['payload_json', 'updated_at']
        );
    }

    /**
     * @return array{values: array<string, mixed>, updatedAt: int}|null updatedAt is epoch milliseconds
     */
    public function find(int $formId, int $customerId, int $storeId): ?array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), [
                'payload_json',
                'updated' => new \Zend_Db_Expr('UNIX_TIMESTAMP(updated_at)'),
            ])
            ->where('form_id = ?', $formId)
            ->where('customer_id = ?', $customerId)
            ->where('store_id = ?', $storeId);
        $row = $connection->fetchRow($select);
        if (!$row) {
            return null;
        }

        return [
            'values' => (array)$this->json->unserialize((string)$row['payload_json']),
            'updatedAt' => (int)$row['updated'] * 1000,
        ];
    }

    public function clear(int $formId, int $customerId, int $storeId): void
    {
        $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['form_id = ?' => $formId, 'customer_id = ?' => $customerId, 'store_id = ?' => $storeId]
        );
    }

    /**
     * @param array<string, mixed> $schema
     * @return string[]
     */
    private function draftableNames(array $schema): array
    {
        $names = [];
        foreach (SchemaFields::all($schema) as $item) {
            if ($this->isDraftable($item)) {
                $names[] = (string)$item['name'];
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function isDraftable(array $field): bool
    {
        $type = (string)($field['type'] ?? '');

        return !$this->fieldTypes->isServerValue($type) && !$this->fieldTypes->isFile($type) && !$this->fieldTypes->isConsent($type)
            && ($field['draft'] ?? true) !== false
            && empty($field['locked']);
    }

    private function isShallow(mixed $value): bool
    {
        if (is_scalar($value)) {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }
        if (array_is_list($value)) {
            return $this->isScalarList($value);
        }
        foreach ($value as $item) {
            if (!is_scalar($item) && !$this->isScalarList($item)) {
                return false;
            }
        }

        return true;
    }

    private function isScalarList(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        return array_filter($value, static fn ($v) => !is_scalar($v)) === [];
    }
}
