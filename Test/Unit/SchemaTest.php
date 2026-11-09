<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use Magento\Framework\DB\ExpressionConverter;
use PHPUnit\Framework\TestCase;

class SchemaTest extends TestCase
{
    private const ETC = __DIR__ . '/../../etc/';

    private const STATS = [
        'furan_formbuilder_stat_day', 'furan_formbuilder_stat_value', 'furan_formbuilder_stat_number',
    ];

    private const STAGES = [
        'furan_formbuilder_stat_stage_day', 'furan_formbuilder_stat_stage_value',
        'furan_formbuilder_stat_stage_number', 'furan_formbuilder_stat_stage_label',
        'furan_formbuilder_stat_stage_journal',
    ];

    /** @var array<string, array{columns: array, primary: string[], indexes: array, uniques: array, fks: array}>|null */
    private ?array $tables = null;

    /** @return array<string, array<string, mixed>> */
    private function tables(): array
    {
        if ($this->tables !== null) {
            return $this->tables;
        }
        $xml = simplexml_load_file(self::ETC . 'db_schema.xml');
        $tables = [];
        foreach ($xml->table as $table) {
            $info = ['columns' => [], 'order' => [], 'primary' => [], 'indexes' => [], 'uniques' => [], 'fks' => []];
            foreach ($table->column as $column) {
                $attrs = [];
                foreach ($column->attributes() as $key => $value) {
                    $attrs[$key] = (string)$value;
                }
                foreach ($column->attributes('xsi', true) as $key => $value) {
                    $attrs[$key] = (string)$value;
                }
                $info['columns'][(string)$column['name']] = $attrs;
                $info['order'][] = (string)$column['name'];
            }
            foreach ($table->constraint as $constraint) {
                $cols = array_map(fn ($c) => (string)$c['name'], iterator_to_array($constraint->column, false));
                $type = (string)$constraint->attributes('xsi', true)['type'];
                $ref = (string)$constraint['referenceId'];
                if ($type === 'primary') {
                    $info['primary'] = $cols;
                } elseif ($type === 'unique') {
                    $info['uniques'][$ref] = $cols;
                } else {
                    $info['fks'][$ref] = [
                        'column' => (string)$constraint['column'],
                        'table' => (string)$constraint['referenceTable'],
                        'referenceColumn' => (string)$constraint['referenceColumn'],
                        'onDelete' => (string)$constraint['onDelete'],
                    ];
                }
            }
            foreach ($table->index as $index) {
                $info['indexes'][(string)$index['referenceId']] =
                    array_map(fn ($c) => (string)$c['name'], iterator_to_array($index->column, false));
            }
            $tables[(string)$table['name']] = $info;
        }
        return $this->tables = $tables;
    }

    private function table(string $name): array
    {
        $this->assertArrayHasKey($name, $this->tables(), $name);
        return $this->tables()[$name];
    }

    private function assertColumn(string $table, string $column, array $expected): void
    {
        $columns = $this->table($table)['columns'];
        $this->assertArrayHasKey($column, $columns, "$table.$column");
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $columns[$column][$key] ?? null, "$table.$column $key");
        }
    }

    private function hasIndex(string $table, array $columns): bool
    {
        $info = $this->table($table);
        return in_array($columns, array_merge(array_values($info['indexes']), array_values($info['uniques'])), true);
    }

    public function testItDeclaresCustomerGroupEmailHashStatusUpdatedAtAndContextColumnsOnSubmissions(): void
    {
        $t = 'furan_formbuilder_submission';
        $this->assertColumn($t, 'customer_group_id', ['type' => 'int', 'unsigned' => 'true', 'nullable' => 'false', 'default' => '0']);
        $this->assertColumn($t, 'email_hash', ['type' => 'varbinary', 'length' => '32', 'nullable' => 'true']);
        $this->assertColumn($t, 'status', ['type' => 'smallint', 'nullable' => 'false', 'default' => '0']);
        $this->assertColumn($t, 'updated_at', ['type' => 'timestamp', 'nullable' => 'true', 'on_update' => 'false']);
        $this->assertColumn($t, 'context_json', ['type' => 'text', 'nullable' => 'true']);
    }

    public function testItDeclaresANotNullThreadTokenRevocationCounterOnSubmissions(): void
    {
        $t = 'furan_formbuilder_submission';
        $this->assertColumn($t, 'thread_token_version', ['type' => 'int', 'unsigned' => 'true', 'nullable' => 'false', 'default' => '0']);
        $whitelist = json_decode((string)file_get_contents(self::ETC . 'db_schema_whitelist.json'), true);
        $this->assertTrue($whitelist[$t]['column']['thread_token_version'] ?? false);
    }

    public function testItDeclaresTheSharedExpiringCounterTable(): void
    {
        $t = 'furan_formbuilder_counter';
        $this->assertColumn($t, 'counter_key', ['type' => 'varbinary', 'length' => '32', 'nullable' => 'false']);
        $this->assertColumn($t, 'hits', ['type' => 'int', 'unsigned' => 'true', 'nullable' => 'false']);
        $this->assertColumn($t, 'expires_at', ['type' => 'int', 'unsigned' => 'true', 'nullable' => 'false']);
        $this->assertSame(['counter_key'], $this->tables()[$t]['primary']);
        $this->assertContains(['expires_at'], array_values($this->tables()[$t]['indexes']));
        $whitelist = json_decode((string)file_get_contents(self::ETC . 'db_schema_whitelist.json'), true);
        $this->assertTrue($whitelist[$t]['index']['FURAN_FORMBUILDER_COUNTER_EXPIRES_AT'] ?? false);
    }

    public function testItDeclaresTheBackgroundPurgeFlagOnForms(): void
    {
        $this->assertColumn('furan_formbuilder_form', 'purge_requested_at', ['type' => 'timestamp', 'nullable' => 'true']);
        $whitelist = json_decode((string)file_get_contents(self::ETC . 'db_schema_whitelist.json'), true);
        $this->assertTrue($whitelist['furan_formbuilder_form']['column']['purge_requested_at'] ?? false);
    }

    public function testItDeclaresTheEmailHashVersionColumnNextToTheEmailHash(): void
    {
        $t = 'furan_formbuilder_submission';
        $this->assertColumn($t, 'email_hash_version', ['type' => 'smallint', 'unsigned' => 'true', 'nullable' => 'true']);
        $order = $this->table($t)['order'];
        $this->assertSame(array_search('email_hash', $order, true) + 1, array_search('email_hash_version', $order, true));
    }

    public function testItDeclaresIndexesForCustomerIdEmailHashFormStatusStatusAndFormSubmissionId(): void
    {
        $t = 'furan_formbuilder_submission';
        $this->assertTrue($this->hasIndex($t, ['customer_id', 'submission_id']));
        $this->assertTrue($this->hasIndex($t, ['email_hash', 'customer_id']));
        $this->assertTrue($this->hasIndex($t, ['form_id', 'status', 'submission_id']));
        $this->assertTrue($this->hasIndex($t, ['status', 'submission_id']));
        $this->assertTrue($this->hasIndex($t, ['form_id', 'submission_id']));
    }

    public function testItDeclaresThePendingPrivacyWorkTableKeyedBySubmissionAndCascadingFromIt(): void
    {
        $t = 'furan_formbuilder_privacy_pending';
        $this->assertSame(['submission_id'], $this->table($t)['primary']);
        $this->assertColumn($t, 'submission_id', ['type' => 'int', 'unsigned' => 'true', 'nullable' => 'false']);
        $this->assertColumn($t, 'customer_id', ['type' => 'int', 'unsigned' => 'true', 'nullable' => 'false']);
        $this->assertColumn($t, 'mode', ['type' => 'varchar', 'nullable' => 'false']);
        $this->assertColumn($t, 'attempts', ['type' => 'smallint', 'unsigned' => 'true', 'nullable' => 'false', 'default' => '0']);
        $this->assertColumn($t, 'next_attempt_at', ['type' => 'datetime', 'nullable' => 'false']);
        $this->assertColumn($t, 'created_at', ['type' => 'timestamp', 'nullable' => 'false', 'default' => 'CURRENT_TIMESTAMP']);
        $this->assertCascade($t, 'submission_id', 'furan_formbuilder_submission');
        $this->assertCount(1, $this->table($t)['fks'], 'the customer row is already gone, so customer_id has no FK');
        $this->assertTrue($this->hasIndex($t, ['next_attempt_at', 'submission_id']));
        $this->assertTrue($this->hasIndex($t, ['customer_id', 'submission_id']));
    }

    public function testItIndexesTheEmailFingerprintForKeysetPagingBySubmissionId(): void
    {
        $this->assertTrue($this->hasIndex('furan_formbuilder_submission', ['email_hash', 'submission_id']));
    }

    public function testItDeclaresTheFrozenBucketSpecColumnsOnTheStatFieldDictionary(): void
    {
        $t = 'furan_formbuilder_stat_field';
        $this->assertSame(['field_id'], $this->table($t)['primary']);
        $this->assertColumn($t, 'field_id', ['type' => 'int', 'unsigned' => 'true', 'identity' => 'true']);
        $this->assertColumn($t, 'field_key', ['type' => 'varchar', 'length' => '80', 'nullable' => 'false']);
        $this->assertColumn($t, 'kind', ['type' => 'smallint', 'nullable' => 'false']);
        $this->assertColumn($t, 'bucket_step', ['type' => 'double', 'nullable' => 'true']);
        $this->assertContains(['form_id', 'field_key'], array_values($this->table($t)['uniques']));
    }

    public function testItDeclaresRespondentMessageDraftAndStatTablesWithTheDocumentedPrimaryKeys(): void
    {
        $expected = [
            'furan_formbuilder_respondent' => ['form_id', 'respondent_key'],
            'furan_formbuilder_message' => ['message_id'],
            'furan_formbuilder_draft' => ['form_id', 'customer_id', 'store_id'],
            'furan_formbuilder_stat_day' => ['form_id', 'grain', 'is_rollup', 'period', 'store_id', 'customer_group_id'],
            'furan_formbuilder_stat_value' => ['field_id', 'grain', 'is_rollup', 'period', 'store_id', 'customer_group_id', 'value_key'],
            'furan_formbuilder_stat_number' => ['field_id', 'grain', 'is_rollup', 'period', 'store_id', 'customer_group_id'],
            'furan_formbuilder_stat_label' => ['field_id', 'value_key'],
            'furan_formbuilder_stat_journal' => ['journal_id'],
            'furan_formbuilder_stat_state' => ['form_id'],
        ];
        foreach ($expected as $table => $primary) {
            $this->assertSame($primary, $this->table($table)['primary'], $table);
        }
        $this->assertColumn('furan_formbuilder_respondent', 'respondent_key', ['type' => 'varbinary', 'length' => '33']);
        $this->assertColumn('furan_formbuilder_stat_value', 'value_key', ['type' => 'varbinary', 'length' => '16']);
        $this->assertColumn('furan_formbuilder_stat_journal', 'deltas', ['type' => 'mediumtext']);
        $this->assertColumn('furan_formbuilder_stat_journal', 'journal_id', ['type' => 'bigint', 'unsigned' => 'true', 'identity' => 'true']);
        $this->assertTrue($this->hasIndex('furan_formbuilder_stat_journal', ['form_id', 'journal_id']));
        $this->assertTrue($this->hasIndex('furan_formbuilder_message', ['submission_id', 'message_id']));
        $this->assertTrue($this->hasIndex('furan_formbuilder_draft', ['customer_id']));
        $this->assertTrue($this->hasIndex('furan_formbuilder_draft', ['store_id', 'updated_at']));
    }

    public function testItDeclaresTheStatCountersAsSignedWithNonNullableStoreAndGroup(): void
    {
        foreach (self::STATS as $table) {
            $this->assertColumn($table, 'store_id', ['type' => 'smallint', 'unsigned' => 'true', 'nullable' => 'false']);
            $this->assertColumn($table, 'customer_group_id', ['nullable' => 'false']);
            $this->assertArrayNotHasKey('unsigned', $this->table($table)['columns']['hits'] ?? []);
        }
        $this->assertColumn('furan_formbuilder_stat_day', 'submissions', ['type' => 'int', 'nullable' => 'false', 'default' => '0']);
        $this->assertColumn('furan_formbuilder_stat_value', 'hits', ['type' => 'int', 'nullable' => 'false', 'default' => '0']);
        $this->assertColumn('furan_formbuilder_stat_number', 'n', ['type' => 'int', 'nullable' => 'false', 'default' => '0']);
        $this->assertColumn('furan_formbuilder_stat_number', 'total', ['type' => 'double', 'nullable' => 'false', 'default' => '0']);
        $this->assertArrayNotHasKey('unsigned', $this->table('furan_formbuilder_stat_value')['columns']['hits']);
        $this->assertArrayNotHasKey('unsigned', $this->table('furan_formbuilder_stat_day')['columns']['submissions']);
        $this->assertArrayNotHasKey('unsigned', $this->table('furan_formbuilder_stat_number')['columns']['n']);
    }

    public function testItCascadesEveryNewTableFromFormsOrSubmissionsOnDelete(): void
    {
        $fromForm = ['respondent', 'draft', 'stat_field', 'stat_day', 'stat_journal', 'stat_state']
            + [6 => 'stat_stage_day', 'stat_stage_value', 'stat_stage_number', 'stat_stage_label', 'stat_stage_journal'];
        foreach ($fromForm as $name) {
            $this->assertCascade('furan_formbuilder_' . $name, 'form_id', 'furan_formbuilder_form');
        }
        $this->assertCascade('furan_formbuilder_respondent', 'submission_id', 'furan_formbuilder_submission');
        $this->assertCascade('furan_formbuilder_message', 'submission_id', 'furan_formbuilder_submission');
        foreach (['stat_value', 'stat_number', 'stat_label'] as $name) {
            $this->assertCascade('furan_formbuilder_' . $name, 'field_id', 'furan_formbuilder_stat_field');
        }
        $this->assertCascade('furan_formbuilder_draft', 'customer_id', 'customer_entity');
        $this->assertCascade('furan_formbuilder_draft', 'store_id', 'store');
    }

    public function testItLinksFormsToTheFieldGroupsTheyContainAndCascadesFromBoth(): void
    {
        $this->assertCascade('furan_formbuilder_field_group_form', 'group_id', 'furan_formbuilder_field_group');
        $this->assertCascade('furan_formbuilder_field_group_form', 'form_id', 'furan_formbuilder_form');
        $this->assertTrue($this->hasIndex('furan_formbuilder_field_group_form', ['form_id']));
    }

    public function testItLinksFormsToTheSavedRulesTheyUseByCodeAndCascadesFromBoth(): void
    {
        $t = 'furan_formbuilder_saved_rule_form';
        $this->assertSame(['code', 'form_id'], $this->table($t)['primary']);
        $this->assertColumn($t, 'code', ['type' => 'varchar', 'length' => '64', 'nullable' => 'false']);
        $this->assertCascade($t, 'code', 'furan_formbuilder_rule');
        $this->assertCascade($t, 'form_id', 'furan_formbuilder_form');
        $this->assertTrue($this->hasIndex($t, ['form_id']));
    }

    private function assertCascade(string $table, string $column, string $reference): void
    {
        $match = array_filter(
            $this->table($table)['fks'],
            fn (array $fk) => $fk['column'] === $column && $fk['table'] === $reference && $fk['onDelete'] === 'CASCADE'
        );
        $this->assertNotEmpty($match, "$table.$column -> $reference");
    }

    public function testItKeepsARespondentRowWhoseSubmissionWasDetachedAndIndexesRespondentKeysAndSubmissionIds(): void
    {
        $t = 'furan_formbuilder_respondent';
        $this->assertColumn($t, 'submission_id', ['type' => 'int', 'unsigned' => 'true', 'nullable' => 'true']);
        $this->assertTrue($this->hasIndex($t, ['submission_id']));
        $this->assertTrue($this->hasIndex($t, ['respondent_key']));
    }

    public function testItPlacesTheRollupFlagAfterTheGrainInEveryStatPrimaryKey(): void
    {
        foreach (array_merge(self::STATS, ['furan_formbuilder_stat_stage_day', 'furan_formbuilder_stat_stage_value', 'furan_formbuilder_stat_stage_number']) as $table) {
            $primary = $this->table($table)['primary'];
            $this->assertSame(array_search('grain', $primary, true) + 1, array_search('is_rollup', $primary, true), $table);
            $this->assertColumn($table, 'is_rollup', ['type' => 'smallint', 'unsigned' => 'true', 'nullable' => 'false', 'default' => '0']);
        }
    }

    public function testItDeclaresRebuildStagingTablesKeyedByFormAndRebuildIdWithTheLiveColumns(): void
    {
        $expected = [
            'furan_formbuilder_stat_stage_day' => ['form_id', 'rebuild_id', 'grain', 'is_rollup', 'period', 'store_id', 'customer_group_id'],
            'furan_formbuilder_stat_stage_value' => ['form_id', 'rebuild_id', 'field_id', 'grain', 'is_rollup', 'period', 'store_id', 'customer_group_id', 'value_key'],
            'furan_formbuilder_stat_stage_number' => ['form_id', 'rebuild_id', 'field_id', 'grain', 'is_rollup', 'period', 'store_id', 'customer_group_id'],
            'furan_formbuilder_stat_stage_label' => ['form_id', 'rebuild_id', 'field_id', 'value_key'],
            'furan_formbuilder_stat_stage_journal' => ['form_id', 'rebuild_id', 'journal_id'],
        ];
        foreach ($expected as $table => $primary) {
            $this->assertSame($primary, $this->table($table)['primary'], $table);
            $this->assertColumn($table, 'rebuild_id', ['type' => 'int', 'unsigned' => 'true', 'nullable' => 'false']);
            $this->assertSame(['form_id'], array_column($this->table($table)['fks'], 'column'), $table);
        }
        $this->assertColumn('furan_formbuilder_stat_stage_day', 'submissions', ['type' => 'int']);
        $this->assertColumn('furan_formbuilder_stat_stage_value', 'hits', ['type' => 'int']);
        $this->assertColumn('furan_formbuilder_stat_stage_number', 'total', ['type' => 'double']);
        $this->assertColumn('furan_formbuilder_stat_stage_number', 'n', ['type' => 'int']);
        $this->assertColumn('furan_formbuilder_stat_stage_label', 'label', ['type' => 'varchar', 'length' => '255']);
        $this->assertColumn('furan_formbuilder_stat_stage_value', 'value_key', ['type' => 'varbinary', 'length' => '16']);
        $this->assertColumn('furan_formbuilder_stat_stage_journal', 'journal_id', ['type' => 'bigint', 'unsigned' => 'true']);
        $this->assertColumn('furan_formbuilder_stat_stage_journal', 'deltas', ['type' => 'mediumtext']);
        foreach (self::STAGES as $table) {
            $this->assertSame([], $this->table($table)['indexes'], $table);
        }
    }

    public function testItDeclaresTheRebuildIdAndRebuildRequestColumnsOnTheStatState(): void
    {
        $t = 'furan_formbuilder_stat_state';
        $this->assertColumn($t, 'rebuilding', ['type' => 'smallint', 'nullable' => 'false', 'default' => '0']);
        $this->assertColumn($t, 'rebuild_started_at', ['type' => 'timestamp', 'nullable' => 'true']);
        $this->assertColumn($t, 'needs_rebuild', ['type' => 'smallint', 'nullable' => 'false', 'default' => '0']);
        $this->assertColumn($t, 'rebuild_id', ['type' => 'int', 'unsigned' => 'true', 'nullable' => 'false', 'default' => '0']);
        $this->assertColumn($t, 'rebuild_requested', ['type' => 'smallint', 'nullable' => 'false', 'default' => '0']);
        $this->assertColumn($t, 'rebuild_requested_at', ['type' => 'timestamp', 'nullable' => 'true']);
        $this->assertArrayNotHasKey('folded_through', $this->table($t)['columns']);
    }

    public function testItDropsTheDeliveryEndpointForeignKeyAndKeepsTheSubmissionOne(): void
    {
        $table = $this->table('furan_formbuilder_webhook_delivery');
        $columns = array_column($table['fks'], 'column');
        $this->assertSame(['submission_id'], $columns);
        $this->assertSame('furan_formbuilder_submission', array_values($table['fks'])[0]['table']);
        $this->assertContains(
            ['endpoint_id', 'delivery_id'],
            array_values($table['indexes'])
        );
        $this->assertContains(['status', 'endpoint_id', 'next_attempt_at', 'delivery_id'], array_values($table['indexes']));
        $this->assertNotContains(['status', 'next_attempt_at', 'delivery_id'], array_values($table['indexes']));

        $whitelist = json_decode((string)file_get_contents(self::ETC . 'db_schema_whitelist.json'), true);
        $this->assertArrayHasKey(
            'FURAN_FORMBUILDER_WEBHOOK_DELIVERY_ENDPOINT_ID_FURAN_FORMBUILDER_WEBHOOK_ENDPOINT_ID',
            $whitelist['furan_formbuilder_webhook_delivery']['constraint']
        );
        $this->assertArrayHasKey(
            'FURAN_FORMBUILDER_WEBHOOK_DELIVERY_STATUS_NEXT_ATTEMPT_AT_DELIVERY_ID',
            $whitelist['furan_formbuilder_webhook_delivery']['index']
        );
        // Magento drops by the name the database holds, which is hashed once a referenceId passes 64 characters.
        $this->assertArrayHasKey('FK_7F939654C157A4124175FAA58B9915E3', $whitelist['furan_formbuilder_webhook_delivery']['constraint']);
        $this->assertArrayHasKey('IDX_25F25FBABDB053AD9D88B66E28249A98', $whitelist['furan_formbuilder_webhook_delivery']['index']);
    }

    public function testItKeepsTheWhitelistInStepWithEveryDeclaredTableIndexAndConstraint(): void
    {
        $whitelist = json_decode((string)file_get_contents(self::ETC . 'db_schema_whitelist.json'), true);
        foreach ($this->tables() as $name => $info) {
            $this->assertArrayHasKey($name, $whitelist, $name);
            foreach ($info['order'] as $column) {
                $this->assertArrayHasKey($column, $whitelist[$name]['column'], "$name.$column");
            }
            foreach (array_keys($info['indexes']) as $index) {
                $this->assertArrayHasKey($index, $whitelist[$name]['index'] ?? [], "$name $index");
            }
            $constraints = ['PRIMARY', ...array_keys($info['uniques']), ...array_keys($info['fks'])];
            foreach ($constraints as $constraint) {
                $this->assertArrayHasKey($constraint, $whitelist[$name]['constraint'] ?? [], "$name $constraint");
            }
        }
    }

    public function testItListsTheHashedNameOfEveryGeneratedIndexAndConstraintNameOver64Characters(): void
    {
        $whitelist = json_decode((string)file_get_contents(self::ETC . 'db_schema_whitelist.json'), true);
        $checked = 0;
        foreach ($this->tables() as $name => $info) {
            $generated = [];
            foreach ($info['indexes'] as $columns) {
                $generated[] = $this->generated('index', $name . '_' . implode('_', $columns), 'idx_');
            }
            foreach ($info['uniques'] as $columns) {
                $generated[] = $this->generated('constraint', $name . '_' . implode('_', $columns), 'unq_');
            }
            foreach ($info['fks'] as $fk) {
                $full = sprintf('%s_%s_%s_%s', $name, $fk['column'], $fk['table'], $fk['referenceColumn']);
                $generated[] = $this->generated('constraint', $full, 'fk_');
            }
            foreach ($generated as [$section, $key, $full]) {
                if ($key === strtoupper($full)) {
                    continue;
                }
                $checked++;
                $this->assertArrayHasKey($key, $whitelist[$name][$section] ?? [], "$name $key");
            }
        }
        $this->assertGreaterThan(0, $checked);
    }

    /** @return array{string, string, string} section, name Magento stores, name before shortening */
    private function generated(string $section, string $full, string $prefix): array
    {
        return [$section, strtoupper(ExpressionConverter::shortenEntityName($full, $prefix)), $full];
    }
}
