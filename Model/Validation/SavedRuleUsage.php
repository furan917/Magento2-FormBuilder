<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation;

use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Furan\FormBuilder\Model\Schema\SchemaFields;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Which forms have a field with a `preset` rule pointing at a saved rule.
 */
class SavedRuleUsage
{
    public const TABLE = 'furan_formbuilder_saved_rule_form';
    private const RULE_TABLE = 'furan_formbuilder_rule';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @param array<mixed> $schema
     * @return list<string>
     */
    public static function codes(array $schema): array
    {
        $codes = [];
        foreach (SchemaFields::all($schema) as $field) {
            foreach ((array)($field['rules'] ?? []) as $rule) {
                $value = is_array($rule) && ($rule['rule'] ?? '') === 'preset' ? ($rule['value'] ?? null) : null;
                if (is_string($value) && str_starts_with($value, RuleLibrary::SAVED_PREFIX)) {
                    $code = substr($value, strlen(RuleLibrary::SAVED_PREFIX));
                    if ($code !== '') {
                        $codes[$code] = $code;
                    }
                }
            }
        }
        return array_values($codes);
    }

    /**
     * Codes without a saved rule are dropped.
     *
     * @param array<mixed> $schema
     */
    public function sync(int $formId, array $schema): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $connection->delete($table, ['form_id = ?' => $formId]);
        $codes = self::codes($schema);
        if ($codes === []) {
            return;
        }
        $select = $connection->select()
            ->from($this->resource->getTableName(self::RULE_TABLE), ['code', 'form_id' => new \Zend_Db_Expr((string)$formId)])
            ->where('code IN (?)', $codes);
        $connection->query($connection->insertFromSelect($select, $table, ['code', 'form_id'], AdapterInterface::INSERT_IGNORE));
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    public function forms(string $code): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['link' => $this->resource->getTableName(self::TABLE)], [])
            ->join(['form' => $this->resource->getTableName(FormResource::TABLE_FORM)], 'form.form_id = link.form_id', ['form_id', 'title'])
            ->where('link.code = ?', $code)
            ->order('link.form_id ASC');
        return array_values(array_map(
            static fn (array $row): array => ['id' => (int)$row['form_id'], 'title' => (string)$row['title']],
            $connection->fetchAll($select)
        ));
    }

    /**
     * @param string[] $codes
     * @return array<string, int> code => number of forms
     */
    public function counts(array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), ['code', new \Zend_Db_Expr('COUNT(*)')])
            ->where('code IN (?)', array_values($codes))
            ->group('code');
        $counts = [];
        foreach ($connection->fetchPairs($select) as $code => $count) {
            $counts[(string)$code] = (int)$count;
        }
        return $counts;
    }
}
