<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldGroup;

use Furan\FormBuilder\Model\ResourceModel\FieldGroup as FieldGroupResource;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Furan\FormBuilder\Model\Schema\SchemaFields;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Which forms contain items inserted from a group, read from the `source_group` marker the builder puts on them.
 */
class GroupUsage
{
    public const TABLE = 'furan_formbuilder_field_group_form';
    public const LIST_LIMIT = 20;

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @param array<mixed> $schema
     * @return list<int>
     */
    public static function groupIds(array $schema): array
    {
        $ids = [];
        SchemaFields::map($schema, static function (array $item) use (&$ids): array {
            $id = $item['source_group'] ?? null;
            if (is_int($id) && $id > 0) {
                $ids[$id] = $id;
            }
            return $item;
        }, ['section', 'field', 'content']);
        return array_values($ids);
    }

    /**
     * Ids of groups deleted since the items were inserted are dropped.
     *
     * @param array<mixed> $schema
     */
    public function sync(int $formId, array $schema): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $connection->delete($table, ['form_id = ?' => $formId]);
        $ids = self::groupIds($schema);
        if ($ids === []) {
            return;
        }
        $select = $connection->select()
            ->from($this->resource->getTableName(FieldGroupResource::TABLE), ['group_id', 'form_id' => new \Zend_Db_Expr((string)$formId)])
            ->where('group_id IN (?)', $ids);
        $connection->query($connection->insertFromSelect($select, $table, ['group_id', 'form_id'], AdapterInterface::INSERT_IGNORE));
    }

    /**
     * @return array{rows: list<array{id: int, title: string}>, total: int}
     */
    public function formsUsing(int $groupId, int $limit = self::LIST_LIMIT): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $select = $connection->select()
            ->from(['link' => $table], [])
            ->join(['form' => $this->resource->getTableName(FormResource::TABLE_FORM)], 'form.form_id = link.form_id', ['form_id', 'title'])
            ->where('link.group_id = ?', $groupId)
            ->order('link.form_id ASC')
            ->limit($limit);
        $rows = array_map(
            static fn (array $row): array => ['id' => (int)$row['form_id'], 'title' => (string)$row['title']],
            $connection->fetchAll($select)
        );
        $total = count($rows) < $limit
            ? count($rows)
            : (int)$connection->fetchOne(
                $connection->select()->from($table, [new \Zend_Db_Expr('COUNT(*)')])->where('group_id = ?', $groupId)
            );
        return ['rows' => array_values($rows), 'total' => $total];
    }

    /**
     * @param int[] $groupIds
     * @return array<int, int> group id => number of forms
     */
    public function counts(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), ['group_id', new \Zend_Db_Expr('COUNT(*)')])
            ->where('group_id IN (?)', array_values($groupIds))
            ->group('group_id');
        $counts = [];
        foreach ($connection->fetchPairs($select) as $id => $count) {
            $counts[(int)$id] = (int)$count;
        }
        return $counts;
    }
}
