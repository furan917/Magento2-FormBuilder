<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;

class EndpointRepository
{
    public const TABLE = 'furan_formbuilder_webhook';

    public const MAX_PREVIOUS_HOURS = 72;

    /** Every column a reader may select; the secret columns are deliberately absent. */
    private const VIEW_COLUMNS = [
        'endpoint_id', 'name', 'url', 'is_active', 'scope_type', 'scope_ids', 'all_forms', 'form_ids', 'events',
        'field_mode', 'fields', 'include_personal', 'debug_responses', 'previous_expires_at', 'disabled_reason',
        'failing_since', 'consecutive_failures', 'circuit_open_until',
    ];

    private const ROUTE_COLUMNS = ['endpoint_id', 'events', 'all_forms', 'form_ids', 'scope_type', 'scope_ids'];

    private const FAILURE_STATE_CLEARED = [
        'consecutive_failures' => 0, 'circuit_open_until' => null, 'failing_since' => null, 'disabled_reason' => null,
    ];

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Json $json,
        private readonly Secrets $secrets,
        private readonly Audit $audit,
        private readonly EndpointInput $validator,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @param array<string, mixed> $input */
    public function create(array $input, ?int $adminId): EndpointSecret
    {
        $input = $this->validator->normalize($input);
        $plain = $this->secrets->generate();
        /** @var Mysql $connection lastInsertId() is not on AdapterInterface */
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $connection->insert($table, [
            'name' => $input['name'],
            'url' => $input['url'],
            'is_active' => (int)$input['is_active'],
            'scope_type' => $input['scope_type'],
            'scope_ids' => $this->json->serialize($input['scope_ids']),
            'all_forms' => (int)$input['all_forms'],
            'form_ids' => $this->json->serialize($input['form_ids']),
            'events' => $this->json->serialize($input['events']),
            'field_mode' => $input['field_mode'],
            'fields' => $this->json->serialize($input['fields']),
            'include_personal' => (int)$input['include_personal'],
            'debug_responses' => (int)$input['debug_responses'],
            'secret_enc' => $this->secrets->encrypt($plain),
        ]);
        $id = (int)$connection->lastInsertId($table);
        $this->audit->record($id, $adminId, 'created');

        return new EndpointSecret($id, $plain);
    }

    /**
     * Never touches the secret columns. The caller reads the endpoint first (getView) to know it exists.
     *
     * @param array<string, mixed> $input
     * @param bool $reEnable also clears the failure state, in the same UPDATE that sets is_active
     */
    public function update(int $endpointId, array $input, bool $reEnable = false): void
    {
        $input = $this->validator->normalize($input);
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            ($reEnable ? self::FAILURE_STATE_CLEARED : []) + [
                'name' => $input['name'],
                'url' => $input['url'],
                'is_active' => (int)$input['is_active'],
                'scope_type' => $input['scope_type'],
                'scope_ids' => $this->json->serialize($input['scope_ids']),
                'all_forms' => (int)$input['all_forms'],
                'form_ids' => $this->json->serialize($input['form_ids']),
                'events' => $this->json->serialize($input['events']),
                'field_mode' => $input['field_mode'],
                'fields' => $this->json->serialize($input['fields']),
                'include_personal' => (int)$input['include_personal'],
                'debug_responses' => (int)$input['debug_responses'],
                'updated_at' => gmdate('Y-m-d H:i:s', (int)($this->clock)()),
            ],
            ['endpoint_id = ?' => $endpointId, 'purge_requested_at IS NULL']
        );
    }

    public function countActive(): int
    {
        $connection = $this->resource->getConnection();

        return (int)$connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), [new \Zend_Db_Expr('COUNT(*)')])
                ->where('is_active = 1')
                ->where('purge_requested_at IS NULL')
        );
    }

    /**
     * Hides the endpoint and queues its deliveries for the batched purge; nothing is deleted here.
     *
     * @throws NoSuchEntityException
     */
    public function softDelete(int $endpointId, ?int $adminId): void
    {
        $affected = $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            ['is_active' => 0, 'purge_requested_at' => gmdate('Y-m-d H:i:s', (int)($this->clock)())],
            ['endpoint_id = ?' => $endpointId, 'purge_requested_at IS NULL']
        );
        if ($affected < 1) {
            throw new NoSuchEntityException(__('The webhook endpoint no longer exists.'));
        }
        $this->audit->record($endpointId, $adminId, 'deleted');
    }

    /**
     * Moves the current secret to previous_* inside the UPDATE itself, so the stored secret is never read here.
     * MySQL assigns left to right: previous_secret_enc must be set before secret_enc.
     *
     * @throws NoSuchEntityException
     */
    public function rotate(int $endpointId, int $windowHours, ?int $adminId): EndpointSecret
    {
        $hours = max(0, min(self::MAX_PREVIOUS_HOURS, $windowHours));
        $plain = $this->secrets->generate();
        $keep = $hours > 0;
        $affected = $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            [
                'previous_secret_enc' => $keep ? new \Zend_Db_Expr('secret_enc') : null,
                'previous_expires_at' => $keep ? gmdate('Y-m-d H:i:s', (int)($this->clock)() + $hours * 3600) : null,
                'secret_enc' => $this->secrets->encrypt($plain),
            ],
            ['endpoint_id = ?' => $endpointId, 'purge_requested_at IS NULL']
        );
        if ($affected < 1) {
            throw new NoSuchEntityException(__('The webhook endpoint no longer exists.'));
        }
        $this->audit->record($endpointId, $adminId, 'secret_rotated', $keep ? sprintf('previous kept %dh', $hours) : '');

        return new EndpointSecret($endpointId, $plain);
    }

    /**
     * The one reader that selects the secret columns; for the signer (Secrets) only.
     *
     * @throws NoSuchEntityException
     */
    public function getForSigning(int $endpointId): Endpoint
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from(
                    $this->resource->getTableName(self::TABLE),
                    ['endpoint_id', 'secret_enc', 'previous_secret_enc', 'previous_expires_at']
                )
                ->where('endpoint_id = ?', $endpointId)
                ->limit(1)
        );
        if (!is_array($row)) {
            throw new NoSuchEntityException(__('The webhook endpoint no longer exists.'));
        }

        return new Endpoint(
            (int)$row['endpoint_id'],
            (string)$row['secret_enc'],
            $row['previous_secret_enc'] !== null ? (string)$row['previous_secret_enc'] : null,
            $row['previous_expires_at'] !== null ? (string)$row['previous_expires_at'] : null
        );
    }

    /** @throws NoSuchEntityException */
    public function getView(int $endpointId): EndpointView
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), self::VIEW_COLUMNS)
                ->where('endpoint_id = ?', $endpointId)
                ->where('purge_requested_at IS NULL')
                ->limit(1)
        );
        if (!is_array($row)) {
            throw new NoSuchEntityException(__('The webhook endpoint no longer exists.'));
        }

        return $this->view($row);
    }

    /**
     * One page of endpoints that are not being purged, by primary key (no COUNT, no OFFSET).
     *
     * @return array{rows: EndpointView[], next: ?int} next is the last shown id when more rows follow
     */
    public function page(int $afterId, int $limit = 50): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), self::VIEW_COLUMNS)
                ->where('endpoint_id > ?', $afterId)
                ->where('purge_requested_at IS NULL')
                ->order('endpoint_id ASC')
                ->limit($limit + 1)
        );
        $more = count($rows) > $limit;
        $views = array_map($this->view(...), array_slice($rows, 0, $limit));

        return ['rows' => $views, 'next' => $more && $views !== [] ? $views[count($views) - 1]->id() : null];
    }

    /** @return EndpointRoute[] active endpoints that are not being purged, routing columns only (is_active index) */
    public function activeRoutes(): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), self::ROUTE_COLUMNS)
                ->where('is_active = 1')
                ->where('purge_requested_at IS NULL')
                ->order('endpoint_id ASC')
        );

        return array_map(fn (array $row): EndpointRoute => new EndpointRoute(
            (int)$row['endpoint_id'],
            (string)$row['scope_type'],
            $this->intList($row['scope_ids']),
            (bool)(int)$row['all_forms'],
            $this->intList($row['form_ids']),
            $this->stringList($row['events'])
        ), $rows);
    }

    /**
     * Routing and circuit state of every endpoint that is not being purged, one query, no secret columns.
     *
     * @return array<int, array{active: bool, circuit_open_until: ?string}>
     */
    public function routingStates(): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['endpoint_id', 'is_active', 'circuit_open_until'])
                ->where('purge_requested_at IS NULL')
        );
        $states = [];
        foreach ($rows as $row) {
            $states[(int)$row['endpoint_id']] = [
                'active' => (bool)(int)$row['is_active'],
                'circuit_open_until' => $row['circuit_open_until'] !== null ? (string)$row['circuit_open_until'] : null,
            ];
        }

        return $states;
    }

    /** @param array<string, mixed> $row */
    private function view(array $row): EndpointView
    {
        return new EndpointView(
            (int)$row['endpoint_id'],
            (string)$row['name'],
            (string)$row['url'],
            (bool)(int)$row['is_active'],
            (string)$row['scope_type'],
            $this->intList($row['scope_ids']),
            (bool)(int)$row['all_forms'],
            $this->intList($row['form_ids']),
            $this->stringList($row['events']),
            (string)$row['field_mode'],
            $this->stringList($row['fields']),
            (bool)(int)$row['include_personal'],
            (bool)(int)$row['debug_responses'],
            $row['previous_expires_at'] !== null ? (string)$row['previous_expires_at'] : null,
            $row['disabled_reason'] !== null ? (string)$row['disabled_reason'] : null,
            $row['failing_since'] !== null ? (string)$row['failing_since'] : null,
            (int)$row['consecutive_failures'],
            $row['circuit_open_until'] !== null ? (string)$row['circuit_open_until'] : null
        );
    }

    /** @return int[] */
    private function intList(mixed $stored): array
    {
        return array_map('intval', $this->decodeList($stored));
    }

    /** @return string[] */
    private function stringList(mixed $stored): array
    {
        return array_map('strval', $this->decodeList($stored));
    }

    /** @return array<int, mixed> */
    private function decodeList(mixed $stored): array
    {
        try {
            $list = $this->json->unserialize((string)$stored);
        } catch (\InvalidArgumentException) {
            return [];
        }

        return is_array($list) ? array_values($list) : [];
    }
}
