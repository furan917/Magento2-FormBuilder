<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Schema\SchemaFields;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * A customer's stored answers, shaped for refilling the form. Never files, hidden fields or removed fields.
 */
class PreviousAnswers
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Json $json,
        private readonly Pool $fieldTypes
    ) {
    }

    /**
     * @param array<string, mixed> $schema
     * @return array{answers: array<string, mixed>, savedAt: int}|null savedAt in epoch milliseconds; null when the
     *     submission is gone, foreign or no longer editable
     */
    public function load(int $submissionId, int $formId, int $customerId, array $schema): ?array
    {
        $row = $this->resource->getConnection()->fetchRow(
            'SELECT form_id, customer_id, status, payload_json, UNIX_TIMESTAMP(COALESCE(updated_at, created_at)) AS saved FROM '
            . $this->resource->getTableName('furan_formbuilder_submission') . ' WHERE submission_id = ?',
            [$submissionId]
        );
        if (!is_array($row)
            || (int)$row['form_id'] !== $formId
            || $row['customer_id'] === null
            || (int)$row['customer_id'] !== $customerId
            || !in_array(
                (int)$row['status'],
                [SubmissionInterface::STATUS_NEW, SubmissionInterface::STATUS_IN_PROGRESS],
                true
            )
        ) {
            return null;
        }

        $allowed = $this->shownFieldNames($schema);
        $payload = $this->json->unserialize((string)$row['payload_json']);
        $answers = [];
        foreach (is_array($payload) ? $payload : [] as $entry) {
            $name = is_array($entry) ? (string)($entry['name'] ?? '') : '';
            if (isset($allowed[$name]) && array_key_exists('value', $entry)) {
                $answers[$name] = $entry['value'];
            }
        }
        return ['answers' => $answers, 'savedAt' => (int)$row['saved'] * 1000];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, true>
     */
    private function shownFieldNames(array $schema): array
    {
        $names = [];
        foreach (SchemaFields::all($schema) as $item) {
            if (isset($item['name'])
                && !$this->fieldTypes->isServerValue((string)($item['type'] ?? ''))
                && !$this->fieldTypes->isFile((string)($item['type'] ?? ''))
            ) {
                $names[(string)$item['name']] = true;
            }
        }
        return $names;
    }
}
