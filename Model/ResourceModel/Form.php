<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\ResourceModel;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Model\FieldGroup\GroupUsage;
use Furan\FormBuilder\Model\Form as FormModel;
use Furan\FormBuilder\Model\Form\Schedule;
use Furan\FormBuilder\Model\Schema\SchemaMigrator;
use Furan\FormBuilder\Model\Validation\SavedRuleUsage;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Serialize\Serializer\Json;

class Form extends AbstractDb
{
    public const TABLE_FORM = 'furan_formbuilder_form';
    public const TABLE_FORM_STORE = 'furan_formbuilder_form_store';
    public const TABLE_TRANSLATION = 'furan_formbuilder_form_translation';
    private const UPDATE_CHUNK = 1000;

    public function __construct(
        Context $context,
        private readonly Json $json,
        private readonly SchemaMigrator $schemaMigrator,
        private readonly GroupUsage $groupUsage,
        private readonly SavedRuleUsage $ruleUsage,
        ?string $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
    }

    protected function _construct()
    {
        $this->_init(self::TABLE_FORM, 'form_id');
    }

    /** @return int[] */
    public function lookupStoreIds(int $formId): array
    {
        $connection = $this->connection();
        $select = $connection->select();
        $select->from($this->getTable(self::TABLE_FORM_STORE), 'store_id');
        $select->where('form_id = :form_id');
        return array_map('intval', $connection->fetchCol($select, ['form_id' => $formId]));
    }

    public function getIdByIdentifier(string $identifier): ?int
    {
        $connection = $this->connection();
        $select = $connection->select();
        $select->from($this->getMainTable(), 'form_id');
        $select->where('identifier = :identifier');
        $id = $connection->fetchOne($select, ['identifier' => $identifier]);
        return $id === false || $id === null ? null : (int)$id;
    }

    /** @return array<int, array<string, mixed>> store id => overlay */
    public function lookupTranslations(int $formId): array
    {
        $connection = $this->connection();
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($this->getTable(self::TABLE_TRANSLATION), ['store_id', 'content'])
                ->where('form_id = ?', $formId)
        );
        $translations = [];
        foreach ($rows as $storeId => $content) {
            $overlay = $this->overlay((string)$content);
            if ($overlay !== null) {
                $translations[(int)$storeId] = $overlay;
            }
        }
        return $translations;
    }

    /**
     * One query for a page of forms.
     *
     * @param int[] $formIds
     * @return array<int, array<int, array<string, mixed>>> form id => store id => overlay
     */
    public function lookupTranslationsFor(array $formIds): array
    {
        $connection = $this->connection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->getTable(self::TABLE_TRANSLATION), ['form_id', 'store_id', 'content'])
                ->where('form_id IN (?)', $formIds)
        );
        $translations = [];
        foreach ($rows as $row) {
            $overlay = $this->overlay((string)$row['content']);
            if ($overlay !== null) {
                $translations[(int)$row['form_id']][(int)$row['store_id']] = $overlay;
            }
        }
        return $translations;
    }

    /** @return array<string, mixed>|null */
    private function overlay(string $content): ?array
    {
        try {
            $overlay = $this->json->unserialize($content);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return is_array($overlay) && $overlay !== [] ? $overlay : null;
    }

    /**
     * Changes only the is_active column, so the stored schema is left as it is.
     *
     * @param int[] $formIds
     * @return int forms whose status changed
     */
    public function setActive(array $formIds, bool $active): int
    {
        $changed = 0;
        foreach (array_chunk(array_values(array_unique(array_map('intval', $formIds))), self::UPDATE_CHUNK) as $chunk) {
            $changed += $this->connection()->update(
                $this->getMainTable(),
                [FormInterface::IS_ACTIVE => $active ? 1 : 0],
                ['form_id IN (?)' => $chunk]
            );
        }
        return $changed;
    }

    /** Upgrades a stored schema to the current version; a newer or unreadable one is returned unchanged. */
    public function upgradeSchemaJson(string $json): string
    {
        return $this->schemaMigrator->upgradeJson($json);
    }

    protected function _afterLoad(AbstractModel $object)
    {
        /** @var FormModel $object */
        if ($object->hasData('schema_json')) {
            $object->setData('schema_json', $this->upgradeSchemaJson((string)$object->getData('schema_json')));
        }
        $object->setStoreIds($this->lookupStoreIds((int)$object->getId()));
        $object->setTranslations($this->lookupTranslations((int)$object->getId()));
        return parent::_afterLoad($object);
    }

    protected function _beforeSave(AbstractModel $object)
    {
        // Columns follow the settings (Save, import, duplicate); a partial save without settings keeps them.
        if ($object instanceof FormInterface && $object->hasData('settings_json')) {
            $settings = $object->getSettings();
            foreach (Schedule::KEYS as $column) {
                $object->setData($column, Schedule::toSql($settings[$column] ?? null));
            }
        }
        return parent::_beforeSave($object);
    }

    protected function _afterSave(AbstractModel $object)
    {
        /** @var FormModel $object */
        if ($object->hasData('translations')) {
            $this->saveTranslations((int)$object->getId(), $object->getTranslations());
        }
        if ($object->hasData(FormInterface::SCHEMA_JSON)) {
            $schema = $object->getSchema();
            $this->groupUsage->sync((int)$object->getId(), $schema);
            $this->ruleUsage->sync((int)$object->getId(), $schema);
        }
        if (!$object->hasData('store_ids')) {
            return parent::_afterSave($object);
        }
        $formId = (int)$object->getId();
        $table = $this->getTable(self::TABLE_FORM_STORE);
        $connection = $this->connection();
        $connection->delete($table, ['form_id = ?' => $formId]);

        $rows = [];
        foreach (array_unique($object->getStoreIds()) as $storeId) {
            $rows[] = ['form_id' => $formId, 'store_id' => (int)$storeId];
        }
        if ($rows) {
            $connection->insertMultiple($table, $rows);
        }
        return parent::_afterSave($object);
    }

    /** @param array<int, array<string, mixed>> $translations store id => overlay */
    private function saveTranslations(int $formId, array $translations): void
    {
        $table = $this->getTable(self::TABLE_TRANSLATION);
        $connection = $this->connection();
        $connection->delete($table, ['form_id = ?' => $formId]);
        $rows = [];
        foreach ($translations as $storeId => $overlay) {
            if (is_array($overlay) && $overlay !== []) {
                $rows[] = ['form_id' => $formId, 'store_id' => (int)$storeId, 'content' => $this->json->serialize($overlay)];
            }
        }
        if ($rows) {
            $connection->insertMultiple($table, $rows);
        }
    }

    private function connection(): AdapterInterface
    {
        /** @var AdapterInterface $connection */
        $connection = $this->getConnection();
        return $connection;
    }
}
