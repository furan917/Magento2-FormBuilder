<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Privacy;

use Furan\FormBuilder\Api\Data\SubmissionInterfaceFactory;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Schema\SchemaFields;
use Furan\FormBuilder\Model\Schema\SchemaMigrator;
use Furan\FormBuilder\Model\Schema\UnsupportedSchemaVersionException;
use Furan\FormBuilder\Model\Stats\Journal;
use Furan\FormBuilder\Model\Stats\SeriesRegistry;
use Furan\FormBuilder\Model\Submission\BatchDeleter;
use Furan\FormBuilder\Model\Submission\SummaryBuilder;
use Furan\FormBuilder\Model\Upload\FileRegistry;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/** Blanks personal answers of a deleted customer's submissions while keeping the rest. */
class Anonymiser
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Json $json,
        private readonly Journal $journal,
        private readonly FileRegistry $fileRegistry,
        private readonly SummaryBuilder $summaryBuilder,
        private readonly PersonalData $personalData,
        private readonly SubmissionInterfaceFactory $submissionFactory,
        private readonly SeriesRegistry $seriesRegistry,
        private readonly SchemaMigrator $schemaMigrator,
        private readonly LoggerInterface $logger
    ) {
    }

    /** @param int[] $submissionIds submissions of deleted accounts */
    public function anonymise(array $submissionIds): void
    {
        $connection = $this->resource->getConnection();
        $schemas = [];
        foreach (array_chunk($submissionIds, BatchDeleter::BATCH_SIZE) as $ids) {
            // One commit per batch: the FOR UPDATE locks hold and the 500 row writes are not 500 autocommits.
            $connection->beginTransaction();
            try {
                $this->anonymiseBatch($ids, $schemas);
                $connection->commit();
            } catch (\Throwable $e) {
                $connection->rollBack();
                throw $e;
            }
        }
    }

    /**
     * @param array<int, list<array<string, mixed>>> $schemas flat schema fields by form id, filled lazily
     * @param int[] $ids
     */
    private function anonymiseBatch(array $ids, array &$schemas): void
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            'SELECT submission_id, form_id, payload_json, created_at, store_id, customer_group_id FROM '
            . $this->table('furan_formbuilder_submission') . ' WHERE submission_id IN (' . implode(',', array_map('intval', $ids)) . ') AND customer_id IS NULL FOR UPDATE'
        );
        $this->loadSchemas(array_map(static fn (array $row): int => (int)$row['form_id'], $rows), $schemas);
        $specs = [];
        foreach ($rows as $row) {
            $formId = (int)$row['form_id'];
            $specs[$formId] ??= $this->seriesRegistry->load($formId);
            $this->anonymiseRow($row, $schemas[$formId] ?? [], $specs[$formId]);
        }
        $locked = array_map(static fn (array $row): int => (int)$row['submission_id'], $rows);
        $connection->update(
            $this->table('furan_formbuilder_message'),
            ['body' => ''],
            ['submission_id IN (?)' => $locked, 'author = ?' => 'customer']
        );
        $connection->delete($this->table('furan_formbuilder_respondent'), ['submission_id IN (?)' => $locked]);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $fields
     * @param array<string, array{kind: int, bucket_step: ?float}> $specs
     */
    private function anonymiseRow(array $row, array $fields, array $specs): void
    {
        $id = (int)$row['submission_id'];
        $old = $this->json->unserialize((string)$row['payload_json']);
        $old = is_array($old) ? $old : [];
        $personal = [];
        foreach ($fields as $field) {
            $personal[(string)$field['name']] = $this->personalData->isPersonal($field);
        }
        $new = [];
        $released = [];
        foreach ($old as $entry) {
            // Entries without a schema field are blanked: they may have been personal.
            if ($personal[(string)($entry['name'] ?? '')] ?? true) {
                if (is_string($entry['value']['file'] ?? null)) {
                    $released[] = $entry['value']['file'];
                }
                $entry['value'] = '';
            }
            $new[] = $entry;
        }

        $stored = $this->submissionFactory->create();
        $stored->setFormId((int)$row['form_id'])
            ->setStoreId((int)($row['store_id'] ?? 0))
            ->setCustomerGroupId((int)$row['customer_group_id'])
            ->setCreatedAt((string)$row['created_at'])
            ->setPayload($old);
        $this->journal->replace($stored, $new, $specs);

        $this->resource->getConnection()->update(
            $this->table('furan_formbuilder_submission'),
            [
                'payload_json' => $this->json->serialize($new),
                'summary' => $this->summaryBuilder->build(
                    $fields,
                    array_column($new, 'value', 'name'),
                    (int)($row['store_id'] ?? 0)
                ),
                'remote_ip' => null,
                'email_hash' => null,
                'email_hash_version' => null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['submission_id = ?' => $id]
        );
        $this->fileRegistry->detach($id, $released);
    }

    /**
     * @param array<int, list<array<string, mixed>>> $schemas
     * @param int[] $formIds
     */
    private function loadSchemas(array $formIds, array &$schemas): void
    {
        $missing = array_values(array_diff(array_unique($formIds), array_keys($schemas)));
        if ($missing === []) {
            return;
        }
        $rows = $this->resource->getConnection()->fetchAll(
            'SELECT form_id, schema_json FROM ' . $this->table('furan_formbuilder_form') . ' WHERE form_id IN (' . implode(',', array_map('intval', $missing)) . ')'
        );
        foreach ($missing as $formId) {
            $schemas[$formId] = [];
        }
        foreach ($rows as $row) {
            $schema = $this->json->unserialize((string)$row['schema_json']);
            $schemas[(int)$row['form_id']] = SchemaFields::all(is_array($schema) ? $this->upgraded((int)$row['form_id'], $schema) : []);
        }
    }

    /**
     * A newer schema cannot be read, and must not stop the erase. With no fields known every answer is blanked,
     * which is the safe outcome for an erase.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function upgraded(int $formId, array $schema): array
    {
        try {
            return $this->schemaMigrator->upgrade($schema);
        } catch (UnsupportedSchemaVersionException $e) {
            $this->logger->warning(sprintf('Form %d has schema version %d, newer than supported: all answers are blanked.', $formId, $e->getVersion()));
            return [];
        }
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }
}
