<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Source\SubmissionStatus;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Schema\SchemaFields;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds the versioned envelope from the submission's current stored data.
 * Never emitted: file paths or bytes, links, remote IP, email hash, user agent, session data.
 */
class PayloadBuilder
{
    public const MAX_BYTES = 262144;
    public const VERSION = 1;
    public const TEST_EVENT = 'webhook.test';

    public function __construct(
        private readonly FormRepositoryInterface $forms,
        private readonly PersonalData $personalData,
        private readonly Pool $fieldTypes,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @param array<string, mixed> $eventData
     * @throws PayloadTooLargeException
     */
    public function build(
        EndpointView $endpoint,
        string $msgId,
        string $type,
        string $occurredAt,
        ?SubmissionInterface $submission,
        array $eventData
    ): Envelope {
        if ($submission === null && $type !== self::TEST_EVENT) {
            throw new \InvalidArgumentException('A submission event needs a submission.');
        }
        $data = $submission === null ? $this->testData() : $this->submissionData($endpoint, $type, $submission, $eventData);
        $json = json_encode([
            'id' => $msgId,
            'type' => $type,
            'timestamp' => $occurredAt,
            'version' => self::VERSION,
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $bytes = strlen($json);
        if ($bytes > self::MAX_BYTES) {
            throw new PayloadTooLargeException('The webhook payload is over the size cap.');
        }
        return new Envelope($json, $bytes);
    }

    /** @return array<string, mixed> */
    private function testData(): array
    {
        return ['test' => true, 'message' => 'This is a test delivery from Form Builder.'];
    }

    /**
     * @param array<string, mixed> $eventData
     * @return array<string, mixed>
     */
    private function submissionData(EndpointView $endpoint, string $type, SubmissionInterface $submission, array $eventData): array
    {
        $form = $this->form($submission->getFormId());
        $sent = $this->selectedEntries($endpoint, $submission->getPayload(), $form);
        $values = [];
        $fields = [];
        $files = [];
        foreach ($sent as $entry) {
            $fields[] = ['name' => $entry['name'], 'label' => $entry['label'], 'type' => $entry['type']];
            if ($this->fieldTypes->isFile($entry['type'])) {
                if (is_array($entry['value'])) {
                    $files[] = $this->fileMeta($entry);
                }
            } else {
                $values[$entry['name']] = $entry['value'];
            }
        }
        $data = [
            'submission' => $this->submissionMeta($endpoint, $submission),
            'form' => [
                'id' => $submission->getFormId(),
                'identifier' => $form?->getIdentifier() ?? '',
                'title' => $form?->getTitle() ?? '',
            ],
            'fields' => $fields,
            'values' => (object)$values,
            'files' => $files,
            'product' => $this->product($submission->getContext()),
        ];
        if ($type === 'submission.status_changed') {
            $data['change'] = $this->change($eventData);
        }
        return $data;
    }

    /** @return array<string, mixed> */
    private function submissionMeta(EndpointView $endpoint, SubmissionInterface $submission): array
    {
        $status = $submission->getStatus();
        $meta = [
            'id' => (int)$submission->getId(),
            'reference' => $submission->getReference(),
            'status' => SubmissionStatus::CODES[$status] ?? '',
            'status_id' => $status,
            'store_id' => $submission->getStoreId(),
            'store_code' => $this->storeCode($submission->getStoreId()),
            'created_at' => $this->iso($submission->getCreatedAt()),
            'updated_at' => $this->iso($submission->getUpdatedAt()),
        ];
        if ($endpoint->includePersonal()) {
            $meta['customer_id'] = $submission->getCustomerId();
        }
        return $meta;
    }

    /**
     * Entries that survive the personal-data rule and the endpoint's field selection.
     *
     * @param array<int, array<string, mixed>> $payload
     * @return array<int, array{name: string, label: string, type: string, value: mixed}>
     */
    private function selectedEntries(EndpointView $endpoint, array $payload, ?FormInterface $form): array
    {
        $schemaFields = $form === null ? [] : $this->schemaFields($form->getSchema());
        $selected = [];
        foreach ($payload as $entry) {
            $name = (string)($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if ($endpoint->fieldMode() === 'selected' && !in_array($name, $endpoint->fields(), true)) {
                continue;
            }
            // A field missing from the current schema counts as personal.
            $personal = !isset($schemaFields[$name])
                || $this->personalData->isPersonalForSharing($schemaFields[$name], (string)($entry['type'] ?? ''));
            if ($personal && !$endpoint->includePersonal()) {
                continue;
            }
            $selected[] = [
                'name' => $name,
                'label' => (string)($entry['label'] ?? ''),
                'type' => (string)($entry['type'] ?? ''),
                'value' => $entry['value'] ?? '',
            ];
        }
        return $selected;
    }

    /**
     * @param array{name: string, label: string, type: string, value: mixed} $entry
     * @return array{field: string, name: string, size: int, mime: string}
     */
    private function fileMeta(array $entry): array
    {
        $value = is_array($entry['value']) ? $entry['value'] : [];
        return [
            'field' => $entry['name'],
            'name' => (string)($value['name'] ?? ''),
            'size' => (int)($value['size'] ?? 0),
            'mime' => (string)($value['mime'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed>|null $context
     * @return array{id: mixed, sku: string, name: string}|null
     */
    private function product(?array $context): ?array
    {
        if ($context === null || !isset($context['id'])) {
            return null;
        }
        return [
            'id' => (int)$context['id'],
            'sku' => (string)($context['sku'] ?? ''),
            'name' => (string)($context['name'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $eventData
     * @return array{old_status: string, new_status: string, source: string}
     */
    private function change(array $eventData): array
    {
        return [
            'old_status' => $this->statusCode($eventData['old'] ?? null),
            'new_status' => $this->statusCode($eventData['new'] ?? null),
            'source' => (string)($eventData['source'] ?? ''),
        ];
    }

    private function statusCode(mixed $status): string
    {
        return is_int($status) ? (SubmissionStatus::CODES[$status] ?? '') : (string)$status;
    }

    private function form(int $formId): ?FormInterface
    {
        try {
            return $this->forms->getById($formId);
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    private function storeCode(?int $storeId): string
    {
        if ($storeId === null || $storeId < 1) {
            return '';
        }
        try {
            return $this->storeManager->getStore($storeId)->getCode();
        } catch (NoSuchEntityException) {
            return '';
        }
    }

    private function iso(?string $utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));
        return $date === false ? null : $date->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, array<string, mixed>> field name => field
     */
    private function schemaFields(array $schema): array
    {
        return SchemaFields::byName($schema);
    }
}
