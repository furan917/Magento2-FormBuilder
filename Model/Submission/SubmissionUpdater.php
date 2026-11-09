<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterfaceFactory;
use Furan\FormBuilder\Model\Account\SubmissionPolicy;
use Furan\FormBuilder\Model\Customer\Impersonation;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Stats\Journal;
use Furan\FormBuilder\Model\Upload\FileRegistry;
use Furan\FormBuilder\Model\Webhook\SubmissionDeliveries;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * The one path that changes the answers of a stored submission. Edits send no emails.
 *
 * @phpstan-import-type PayloadEntry from SubmissionInterface
 */
class SubmissionUpdater
{
    private const TABLE = 'furan_formbuilder_submission';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Json $json,
        private readonly Journal $journal,
        private readonly FileRegistry $fileRegistry,
        private readonly SummaryBuilder $summaryBuilder,
        private readonly SubmissionInterfaceFactory $submissionFactory,
        private readonly ManagerInterface $eventManager,
        private readonly LoggerInterface $logger,
        private readonly Impersonation $impersonation,
        private readonly Pool $fieldTypes,
        private readonly SubmissionDeliveries $deliveries,
        private readonly SubmissionPolicy $accountPolicy
    ) {
    }

    /**
     * File answers currently stored, by field name. Unlocked: only decides which required checks to relax;
     * update() re-reads under the lock.
     *
     * @return array<string, array<string, mixed>>
     */
    public function storedFiles(int $submissionId): array
    {
        $json = $this->resource->getConnection()->fetchOne(
            'SELECT payload_json FROM ' . $this->table() . ' WHERE submission_id = ?',
            [$submissionId]
        );
        $files = [];
        foreach ($this->decode(is_string($json) ? $json : '') as $entry) {
            if ($this->fieldTypes->isFile((string)($entry['type'] ?? '')) && isset($entry['value']['file'])) {
                $files[(string)$entry['name']] = $entry['value'];
            }
        }
        return $files;
    }

    /**
     * @param array<int, array<string, mixed>> $fields schema field items
     * @param array<string, mixed> $values validated answers by field name
     * @param array<int, PayloadEntry> $payload payload built from the posted answers and newly stored files
     * @param string[] $newPaths files stored for this edit, linked in the same transaction
     * @throws NotEditableException
     */
    public function update(
        FormInterface $form,
        int $submissionId,
        int $ownerId,
        array $fields,
        array $values,
        array $payload,
        array $newPaths
    ): SubmissionInterface {
        if ($this->impersonation->isActive()) {
            throw new NotEditableException($this->impersonation->refusal(), 403);
        }
        $stored = $this->submissionFactory->create();
        $deliveryIds = [];
        $this->fileRegistry->saveWith(
            $newPaths,
            function () use ($form, $submissionId, $ownerId, $fields, $values, $payload, $stored, &$deliveryIds): int {
                $row = $this->lock($submissionId);
                if ($row === null || !$this->isEditable($row, (int)$form->getId(), $ownerId)) {
                    throw new NotEditableException('This answer can no longer be changed.');
                }
                $old = $this->decode((string)$row['payload_json']);
                $merged = $this->merge($fields, $old, $payload, (int)($row['store_id'] ?? 0));
                $stored->setId($submissionId)
                    ->setFormId((int)$row['form_id'])
                    ->setCustomerId((int)$row['customer_id'])
                    ->setStatus((int)$row['status'])
                    ->setStoreId((int)($row['store_id'] ?? 0))
                    ->setCustomerGroupId((int)$row['customer_group_id'])
                    ->setCreatedAt((string)$row['created_at'])
                    ->setPayload($old);
                $this->journal->replace($stored, $merged);
                $stored->setPayload($merged)->setSummary($this->summaryBuilder->build($fields, $values));
                // Only these columns: a model save would write back status and claim fields read earlier.
                $this->resource->getConnection()->update(
                    $this->table(),
                    [
                        'payload_json' => $this->json->serialize($merged),
                        'summary' => $stored->getSummary(),
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                    ],
                    ['submission_id = ?' => $submissionId]
                );
                $replaced = $this->replacedPaths($old, $merged);
                if ($replaced !== []) {
                    $this->fileRegistry->detach($submissionId, $replaced);
                }
                $deliveryIds = $this->deliveries->record(
                    $submissionId,
                    (int)$row['form_id'],
                    $row['store_id'] === null ? null : (int)$row['store_id'],
                    'submission.updated'
                );

                return $submissionId;
            }
        );
        $this->deliveries->wake($deliveryIds);
        try {
            $this->eventManager->dispatch(
                'furan_formbuilder_submission_updated',
                ['form' => $form, 'submission' => $stored, 'values' => $values]
            );
        } catch (\Throwable $e) {
            // The edit is committed; an observer failure must not make callers delete its files.
            $this->logger->error('Submission update observer failed: ' . $e->getMessage(), ['exception' => $e]);
        }

        return $stored;
    }

    /** @return array<string, mixed>|null */
    private function lock(int $submissionId): ?array
    {
        $row = $this->resource->getConnection()->fetchRow(
            'SELECT form_id, customer_id, status, payload_json, created_at, store_id, customer_group_id FROM '
            . $this->table() . ' WHERE submission_id = ? FOR UPDATE',
            [$submissionId]
        );
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row */
    private function isEditable(array $row, int $formId, int $ownerId): bool
    {
        return (int)$row['form_id'] === $formId
            && $row['customer_id'] !== null
            && (int)$row['customer_id'] === $ownerId
            && $this->accountPolicy->isEditableStatus((int)$row['status']);
    }

    /**
     * Hidden fields, file fields without a new upload and fields of a type unavailable for the submission's store keep the stored entry.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<int, PayloadEntry> $old
     * @param array<int, PayloadEntry> $payload
     * @return array<int, PayloadEntry>
     */
    private function merge(array $fields, array $old, array $payload, int $storeId): array
    {
        $old = array_column($old, null, 'name');
        $new = array_column($payload, null, 'name');
        $merged = [];
        foreach ($fields as $field) {
            $name = (string)$field['name'];
            $type = (string)($field['type'] ?? '');
            $isFile = $this->fieldTypes->isFile($type);
            $kept = $this->fieldTypes->isServerValue($type) || ($isFile && !is_array($new[$name]['value'] ?? null))
                || ($this->fieldTypes->has($type) && !$this->fieldTypes->isUsable($type, $storeId));
            $entry = $kept
                ? ($old[$name] ?? ($isFile ? ($new[$name] ?? null) : null))
                : ($new[$name] ?? null);
            if ($entry !== null) {
                $merged[] = $entry;
            }
        }
        return $merged;
    }

    /**
     * @param array<int, PayloadEntry> $old
     * @param array<int, PayloadEntry> $merged
     * @return string[]
     */
    private function replacedPaths(array $old, array $merged): array
    {
        $kept = [];
        foreach ($merged as $entry) {
            if (isset($entry['value']['file'])) {
                $kept[(string)$entry['value']['file']] = true;
            }
        }
        $released = [];
        foreach ($old as $entry) {
            $path = $entry['value']['file'] ?? null;
            if (is_string($path) && !isset($kept[$path])) {
                $released[] = $path;
            }
        }
        return $released;
    }

    /** @return array<int, PayloadEntry> */
    private function decode(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $data = $this->json->unserialize($json);
        return is_array($data) ? $data : [];
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
