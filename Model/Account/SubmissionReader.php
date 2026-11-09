<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Account;

use Furan\FormBuilder\Model\Submission\Reference;
use Magento\Framework\App\ResourceConnection;

/**
 * Reads a customer's own submissions for the account area. Never selects context_json; the list never selects payload_json.
 */
class SubmissionReader
{
    private const LIST_COLUMNS = ['submission_id', 'form_id', 'status', 'summary', 'created_at', 'updated_at', 'reference'];

    /** @var array<string, array<string, mixed>|null> */
    private array $found = [];

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Keyset page on the (customer_id, submission_id) index; one extra row decides whether an older page exists.
     *
     * @param int $before Last seen submission id; <= 0 means the first page.
     * @return array{rows: array<int, array<string, mixed>>, older: ?int}
     */
    public function listPage(int $customerId, int $before, int $limit): array
    {
        if ($customerId <= 0 || $limit <= 0) {
            return ['rows' => [], 'older' => null];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('furan_formbuilder_submission'), self::LIST_COLUMNS)
            ->where('customer_id = ?', $customerId);
        if ($before > 0) {
            $select->where('submission_id < ?', $before);
        }
        $select->order('submission_id DESC')->limit($limit + 1);

        $rows = (array)$connection->fetchAll($select);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $titles = $this->formTitles(array_map(static fn (array $row): int => (int)$row['form_id'], $rows));
        foreach ($rows as &$row) {
            $row['reference'] = Reference::toString((string)($row['reference'] ?? ''));
            $row['form_title'] = $titles[(int)$row['form_id']] ?? '';
        }
        unset($row);

        $last = $rows ? end($rows) : null;
        return ['rows' => $rows, 'older' => $hasMore && $last ? (int)$last['submission_id'] : null];
    }

    /**
     * One row owned by the customer, or null (also for other customers' ids).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $customerId, int $submissionId): ?array
    {
        if ($customerId <= 0 || $submissionId <= 0) {
            return null;
        }
        $key = $customerId . ':' . $submissionId;
        if (!array_key_exists($key, $this->found)) {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from(
                    $this->resource->getTableName('furan_formbuilder_submission'),
                    [...self::LIST_COLUMNS, 'store_id', 'payload_json']
                )
                ->where('customer_id = ?', $customerId)
                ->where('submission_id = ?', $submissionId)
                ->limit(1);
            $row = $connection->fetchRow($select);
            $this->found[$key] = is_array($row) && $row
                ? ['reference' => Reference::toString((string)($row['reference'] ?? ''))] + $row
                : null;
        }
        return $this->found[$key];
    }

    /**
     * @param int[] $formIds
     * @return array<int, string>
     */
    private function formTitles(array $formIds): array
    {
        $formIds = array_values(array_unique($formIds));
        if (!$formIds) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('furan_formbuilder_form'), ['form_id', 'title'])
            ->where('form_id IN (?)', $formIds);
        $titles = [];
        foreach ((array)$connection->fetchAll($select) as $row) {
            $titles[(int)$row['form_id']] = (string)$row['title'];
        }
        return $titles;
    }
}
