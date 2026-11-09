<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Furan\FormBuilder\Model\Submission\Reference;
use Magento\Framework\App\ResourceConnection;

/** Read side of the delivery log. Every query is an index seek on the delivery or attempt table. */
class DeliveryLog
{
    private const COLUMNS = [
        'delivery_id', 'endpoint_id', 'submission_id', 'event', 'webhook_id', 'replay_of', 'admin_user_id', 'status',
        'attempts', 'last_status_code', 'last_error', 'created_at', 'delivered_at',
    ];

    private const ATTEMPT_TABLE = 'furan_formbuilder_webhook_attempt';
    private const DEBUG_TABLE = 'furan_formbuilder_webhook_attempt_debug';
    private const ATTEMPT_COLUMNS = [
        'attempt_id', 'started_at', 'duration_ms', 'status_code', 'error_class', 'errno', 'connected_ip', 'location',
        'retry_after',
    ];
    private const MAX_ATTEMPTS = 50;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DeliveryIndex $index
    )
    {
    }

    /**
     * Keyset page, newest first: no COUNT, no OFFSET. $find is a submission id (optionally "#12"), a submission
     * reference or a webhook-id; a find narrows to the submission_id or webhook_id index, a status alone uses
     * (endpoint_id, status, delivery_id).
     *
     * @return array{rows: list<array<string, mixed>>, next: ?int, unrecognised: bool} next is the last shown id
     *     when more rows follow; unrecognised when $find is none of the accepted forms
     */
    public function page(int $endpointId, int $afterId, int $limit = 50, ?int $status = null, string $find = ''): array
    {
        $empty = ['rows' => [], 'next' => null, 'unrecognised' => false];
        $where = ['endpoint_id = ?'];
        $bind = [$endpointId];
        $find = trim($find);
        if ($find !== '') {
            $match = $this->match($find);
            if ($match === null) {
                return array_replace($empty, ['unrecognised' => true]);
            }
            if ($match[1] === null) {
                return $empty;
            }
            $where[] = $match[0] . ' = ?';
            $bind[] = $match[1];
            $hint = '';
        } else {
            $hint = $status === null ? $this->index->forEndpoint() : $this->index->forEndpointStatus();
        }
        if ($status !== null) {
            $where[] = 'status = ?';
            $bind[] = $status;
        }
        if ($afterId > 0) {
            $where[] = 'delivery_id < ?';
            $bind[] = $afterId;
        }
        $connection = $this->resource->getConnection();
        $columns = implode(', ', array_map($connection->quoteIdentifier(...), self::COLUMNS));
        $sql = 'SELECT ' . $columns . ' FROM ' . $connection->quoteIdentifier($this->resource->getTableName(Outbox::TABLE))
            . $hint . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY delivery_id DESC LIMIT ' . ($limit + 1);
        $rows = $connection->fetchAll($sql, $bind);
        $more = count($rows) > $limit;
        $shown = array_values(array_map($this->deliveryRow(...), array_slice($rows, 0, $limit)));

        return ['rows' => $shown, 'next' => $more && $shown !== [] ? $shown[count($shown) - 1]['id'] : null] + $empty;
    }

    /**
     * @return array{0: string, 1: int|string|null}|null [column, value]; value null when a reference matches no
     *     submission, null overall when $find is not a recognised form
     */
    private function match(string $find): ?array
    {
        $lower = strtolower($find);
        if (preg_match('/^msg_[0-9a-f]{32}$/D', $lower) === 1) {
            return ['webhook_id', $lower];
        }
        if (preg_match('/^#?([0-9]{1,10})$/D', $find, $id) === 1 && (int)$id[1] <= 4294967295) {
            return ['submission_id', (int)$id[1]];
        }
        $reference = Reference::parse($find);
        if ($reference === null) {
            return null;
        }
        $connection = $this->resource->getConnection();
        $submissionId = $connection->fetchOne(
            'SELECT submission_id FROM ' . $connection->quoteIdentifier($this->resource->getTableName('furan_formbuilder_submission'))
                . ' WHERE reference = ? LIMIT 1',
            [$reference]
        );

        return ['submission_id', $submissionId === false ? null : (int)$submissionId];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function delivery(int $deliveryId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(Outbox::TABLE), self::COLUMNS)
                ->where('delivery_id = ?', $deliveryId)
                ->limit(1)
        );

        return is_array($row) ? $this->deliveryRow($row) : null;
    }

    /**
     * Attempts of one delivery. Request headers and bodies are never stored, so none can be shown; the response
     * excerpt is only joined in when the caller says the viewer may read it.
     *
     * @return list<array<string, mixed>>
     */
    public function attempts(int $deliveryId, bool $withExcerpt): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->resource->getTableName(self::ATTEMPT_TABLE)], self::ATTEMPT_COLUMNS);
        if ($withExcerpt) {
            $select->joinLeft(
                ['d' => $this->resource->getTableName(self::DEBUG_TABLE)],
                'd.attempt_id = a.attempt_id',
                ['excerpt']
            );
        }
        $rows = $connection->fetchAll(
            $select->where('a.delivery_id = ?', $deliveryId)->order('a.attempt_id ASC')->limit(self::MAX_ATTEMPTS)
        );

        return array_values(array_map(fn (array $a): array => $this->attempt($a, $withExcerpt), $rows));
    }

    public function submissionExists(int $submissionId): bool
    {
        $connection = $this->resource->getConnection();

        return $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('furan_formbuilder_submission'), ['submission_id'])
                ->where('submission_id = ?', $submissionId)
                ->limit(1)
        ) !== false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function attempt(array $row, bool $withExcerpt): array
    {
        $attempt = [
            'id' => (int)$row['attempt_id'],
            'started_at' => (string)$row['started_at'],
            'duration_ms' => (int)$row['duration_ms'],
            'code' => $row['status_code'] === null ? null : (int)$row['status_code'],
            'error' => $row['error_class'] === null ? null : (string)$row['error_class'],
            'errno' => $row['errno'] === null ? null : (int)$row['errno'],
            'connected_ip' => $row['connected_ip'] === null ? null : (string)$row['connected_ip'],
            'location' => $row['location'] === null ? null : (string)$row['location'],
            'retry_after' => $row['retry_after'] === null ? null : (int)$row['retry_after'],
        ];
        if ($withExcerpt) {
            $attempt['excerpt'] = !isset($row['excerpt']) ? null : (string)$row['excerpt'];
        }

        return $attempt;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function deliveryRow(array $row): array
    {
        return [
            'id' => (int)$row['delivery_id'],
            'endpoint_id' => (int)$row['endpoint_id'],
            'submission_id' => $row['submission_id'] === null ? null : (int)$row['submission_id'],
            'event' => (string)$row['event'],
            'reference' => (string)$row['webhook_id'],
            'replay_of' => $row['replay_of'] === null ? null : (int)$row['replay_of'],
            'admin_user_id' => $row['admin_user_id'] === null ? null : (int)$row['admin_user_id'],
            'status' => (int)$row['status'],
            'attempts' => (int)$row['attempts'],
            'code' => $row['last_status_code'] === null ? null : (int)$row['last_status_code'],
            'error' => $row['last_error'] === null ? null : (string)$row['last_error'],
            'created_at' => (string)$row['created_at'],
            'delivered_at' => $row['delivered_at'] === null ? null : (string)$row['delivered_at'],
        ];
    }
}
