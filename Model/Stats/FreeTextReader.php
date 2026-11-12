<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Stats;

use Furan\FormBuilder\Api\FieldType\FreeTextTypeInterface;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\ResourceModel\Submission\KeysetIndex;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Submission\ValueFormatter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Latest free-text answers for the analytics page. Each call scans at most SCAN_LIMIT submissions on the
 * (form_id, submission_id) index and decodes only those, so a rarely answered field cannot cause a full read.
 */
class FreeTextReader
{
    public const SCAN_LIMIT = 500;
    public const ANSWER_LIMIT = 20;
    public const WINDOW = 5000;
    private const MAX_CHARS = 500;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Json $json,
        private readonly PersonalData $personalData,
        private readonly KeysetIndex $keysetIndex,
        private readonly Pool $types,
        private readonly ValueFormatter $formatter
    ) {
    }

    /**
     * @param array<array<string, mixed>> $fields schema field items
     * @param array{store_id?: ?int, customer_group_id?: ?int} $filters
     * @return array<string, array{answers: array<int, array{id: int, text: string}>, cursor: ?int}>
     *         cursor = submission_id to continue below, null when the form has no older rows
     */
    public function read(int $formId, array $fields, array $filters = [], ?string $only = null, ?int $cursor = null): array
    {
        $names = [];
        $labels = [];
        foreach ($fields as $field) {
            $name = (string)($field['name'] ?? '');
            if ($name !== '' && ($only === null || $only === $name) && $this->isEligible($field)) {
                $names[] = $name;
                $labels[$name] = $this->formatter->optionLabels($field);
            }
        }
        if ($names === []) {
            return [];
        }

        [$rows, $windowEnd] = $this->scan($formId, $filters, $cursor);
        $next = count($rows) >= self::SCAN_LIMIT ? (int)$rows[count($rows) - 1]['submission_id'] : $windowEnd;
        $result = array_fill_keys($names, ['answers' => [], 'cursor' => $next]);
        $full = [];
        foreach ($rows as $row) {
            $id = (int)$row['submission_id'];
            $values = $this->values((string)$row['payload_json']);
            foreach ($names as $name) {
                $text = $values[$name] ?? '';
                if (isset($full[$name]) || $text === '') {
                    continue;
                }
                $result[$name]['answers'][] = ['id' => $id, 'text' => (string)ValueFormatter::withLabels($labels[$name], $text)];
                if (count($result[$name]['answers']) >= self::ANSWER_LIMIT) {
                    $full[$name] = true;
                    $result[$name]['cursor'] = $id;
                }
            }
            if (count($full) === count($names)) {
                break;
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $field */
    private function isEligible(array $field): bool
    {
        $type = (string)($field['type'] ?? '');
        $fieldType = $this->types->has($type) ? $this->types->get($type) : null;
        return $fieldType instanceof FreeTextTypeInterface && $fieldType->isFreeText()
            && !$this->personalData->isPersonalForSharing($field);
    }

    /**
     * A store or group filter cannot use the (form_id, submission_id) index, so it is applied to at most WINDOW of
     * the form's ids per call; a rare store would otherwise read every row of the form.
     *
     * @param array{store_id?: ?int, customer_group_id?: ?int} $filters
     * @return array{0: array<int, array{submission_id: string, payload_json: string}>, 1: ?int}
     *         rows, and the id to continue below when the window was full
     */
    private function scan(int $formId, array $filters, ?int $cursor): array
    {
        $table = $this->resource->getTableName('furan_formbuilder_submission');
        $hinted = $table . $this->keysetIndex->forForm();
        $connection = $this->resource->getConnection();
        $where = 'form_id = ?';
        $bind = [$formId];
        if ($cursor !== null) {
            $where .= ' AND submission_id < ?';
            $bind[] = $cursor;
        }
        $extra = '';
        $extraBind = [];
        foreach (['store_id', 'customer_group_id'] as $column) {
            if (isset($filters[$column])) {
                $extra .= ' AND ' . $column . ' = ?';
                $extraBind[] = (int)$filters[$column];
            }
        }
        if ($extra === '') {
            return [$connection->fetchAll(
                'SELECT submission_id, payload_json FROM ' . $hinted
                . ' WHERE ' . $where . ' ORDER BY submission_id DESC LIMIT ' . self::SCAN_LIMIT,
                $bind
            ), null];
        }
        $rows = [];
        for ($page = 0; $page < self::WINDOW / self::SCAN_LIMIT; $page++) {
            $ids = array_map('intval', $connection->fetchCol(
                'SELECT submission_id FROM ' . $hinted . ' WHERE ' . $where
                . ' ORDER BY submission_id DESC LIMIT ' . self::SCAN_LIMIT,
                $bind
            ));
            if ($ids === []) {
                return [$rows, null];
            }
            // Ids are ints, so inlining them is injection-safe.
            array_push($rows, ...$connection->fetchAll(
                'SELECT submission_id, payload_json FROM ' . $table . ' WHERE submission_id IN (' . implode(',', $ids) . ')'
                . $extra . ' ORDER BY submission_id DESC',
                $extraBind
            ));
            if (count($rows) >= self::SCAN_LIMIT) {
                return [array_slice($rows, 0, self::SCAN_LIMIT), null];
            }
            $last = (int)end($ids);
            if (count($ids) < self::SCAN_LIMIT) {
                return [$rows, null];
            }
            $where = 'form_id = ? AND submission_id < ?';
            $bind = [$formId, $last];
        }
        return [$rows, $last];
    }

    /** @return array<string, string> field name => trimmed scalar answer */
    private function values(string $payloadJson): array
    {
        try {
            $payload = $this->json->unserialize($payloadJson);
        } catch (\InvalidArgumentException) {
            return [];
        }
        $values = [];
        foreach (is_array($payload) ? $payload : [] as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null) && is_string($entry['value'] ?? null)) {
                $values[$entry['name']] = mb_substr(trim($entry['value']), 0, self::MAX_CHARS);
            }
        }
        return $values;
    }
}
