<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Model\Settings\FormSettings;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Finds the email a stored submission belongs to: the linked account's, else the form's email field answer.
 */
class EmailResolver
{
    /** @var array<int, string> customer id => email */
    private array $customers = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Json $json
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $rows submission_id, form_id, customer_id, payload_json
     * @param array<int, array<string, mixed>> $formSettings settings by form id
     * @return array<int, string> submission_id => email
     */
    public function resolve(array $rows, array $formSettings): array
    {
        $this->loadCustomers(array_column($rows, 'customer_id'));
        $emails = [];
        foreach ($rows as $row) {
            $customerId = (int)($row['customer_id'] ?? 0);
            $email = $customerId > 0 && isset($this->customers[$customerId])
                ? $this->customers[$customerId]
                : $this->answerEmail($row, $formSettings[(int)$row['form_id']] ?? []);
            if (trim($email) !== '') {
                $emails[(int)$row['submission_id']] = $email;
            }
        }
        return $emails;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $settings
     */
    private function answerEmail(array $row, array $settings): string
    {
        $field = FormSettings::submitterEmailField($settings);
        $value = $field === '' ? null : ($this->values($row)[$field] ?? null);
        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function values(array $row): array
    {
        $values = [];
        foreach ($this->decode((string)$row['payload_json']) as $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $values[(string)$entry['name']] = $entry['value'] ?? null;
            }
        }
        return $values;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function decode(string $json): array
    {
        try {
            $data = $this->json->unserialize($json);
        } catch (\InvalidArgumentException) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<int, mixed> $ids
     */
    private function loadCustomers(array $ids): void
    {
        $ids = array_values(array_unique(array_diff(array_map('intval', $ids), [0], array_keys($this->customers))));
        if ($ids === []) {
            return;
        }
        $sql = 'SELECT entity_id, email FROM ' . $this->resource->getTableName('customer_entity')
            . ' WHERE entity_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        foreach ($this->resource->getConnection()->fetchAll($sql, $ids) as $customer) {
            $this->customers[(int)$customer['entity_id']] = (string)$customer['email'];
        }
    }
}
