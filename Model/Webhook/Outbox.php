<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Writes webhook delivery rows. record(), recordTest() and recordReplay() only insert: they never begin, commit
 * or roll back, so the caller runs them inside its own transaction. wake() is called after that commit.
 */
class Outbox
{
    public const TABLE = 'furan_formbuilder_webhook_delivery';
    public const TOPIC = 'furan_formbuilder.webhook';
    public const TEST_EVENT = 'webhook.test';
    private const WAKE_BATCH = 100;
    private const INSERT_CHUNK = 1000;
    private const EVENT_DATA_LENGTH = 255;

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EndpointResolver $resolver,
        private readonly Json $json,
        private readonly PublisherInterface $publisher,
        private readonly LoggerInterface $logger,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * @param array<string, mixed> $eventData only old, new (ints) and source (a short word) are kept
     * @return int[] delivery ids
     */
    public function record(int $submissionId, int $formId, ?int $storeId, string $event, array $eventData = []): array
    {
        return $this->recordMany($event, [
            ['submissionId' => $submissionId, 'formId' => $formId, 'storeId' => $storeId, 'eventData' => $eventData],
        ]);
    }

    /**
     * One insert and one read-back per chunk of rows, whatever the number of submissions.
     *
     * @param array<int, array{submissionId: int, formId: int, storeId: ?int, eventData: array<string, mixed>}> $items
     * @return int[] delivery ids
     */
    public function recordMany(string $event, array $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $data = $this->encodeEventData($item['eventData']);
            foreach ($this->resolver->match($item['formId'], $item['storeId'], $event) as $endpoint) {
                $rows[] = $this->row($endpoint->id(), $item['submissionId'], $event, $data, MessageId::generate());
            }
        }
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $ids = [];
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $connection->insertMultiple($table, $chunk);
            // Read the ids back by message id instead of assuming consecutive auto-increment values.
            $found = $connection->fetchCol(
                $connection->select()->from($table, ['delivery_id'])
                    ->where('webhook_id IN (?)', array_column($chunk, 'webhook_id'))
            );
            array_push($ids, ...array_map('intval', $found));
        }
        sort($ids);

        return $ids;
    }

    public function recordTest(int $endpointId, int $adminId): int
    {
        return $this->insert($this->row($endpointId, null, self::TEST_EVENT, null, MessageId::generate(), null, $adminId));
    }

    /** @param bool $newId true = Resend with a new webhook id, false = Redeliver with the original one */
    public function recordReplay(int $originalId, int $adminId, bool $newId): int
    {
        $connection = $this->resource->getConnection();
        $original = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['endpoint_id', 'submission_id', 'event', 'event_data', 'webhook_id'])
                ->where('delivery_id = ?', $originalId)
        );
        if (!is_array($original)) {
            throw new \InvalidArgumentException('Unknown webhook delivery.');
        }
        $submissionId = $original['submission_id'] === null ? null : (int)$original['submission_id'];
        $eventData = $original['event_data'] === null ? null : (string)$original['event_data'];

        return $this->insert($this->row(
            (int)$original['endpoint_id'],
            $submissionId,
            (string)$original['event'],
            $eventData,
            $newId ? MessageId::generate() : (string)$original['webhook_id'],
            $originalId,
            $adminId
        ));
    }

    /**
     * Call after the transaction committed. A failure is swallowed: the cron drain picks the rows up.
     *
     * @param int[] $ids
     */
    public function wake(array $ids): void
    {
        foreach (array_chunk(array_values($ids), self::WAKE_BATCH) as $chunk) {
            try {
                $this->publisher->publish(self::TOPIC, $this->json->serialize(['ids' => array_map('intval', $chunk)]));
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Form Builder could not queue a webhook wake-up; the cron drain delivers.',
                    ['exception_class' => $e::class, 'delivery_count' => count($chunk)]
                );
            }
        }
    }

    /** @return array<string, mixed> */
    private function row(
        int $endpointId,
        ?int $submissionId,
        string $event,
        ?string $eventData,
        string $webhookId,
        ?int $replayOf = null,
        ?int $adminId = null
    ): array {
        $now = gmdate('Y-m-d H:i:s', (int)($this->clock)());

        return [
            'endpoint_id' => $endpointId,
            'submission_id' => $submissionId,
            'event' => $event,
            'event_data' => $eventData,
            'webhook_id' => $webhookId,
            'replay_of' => $replayOf,
            'admin_user_id' => $adminId,
            'status' => DeliveryStatus::PENDING,
            'attempts' => 0,
            'next_attempt_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @param array<string, mixed> $row */
    private function insert(array $row): int
    {
        /** @var Mysql $connection lastInsertId() is not on AdapterInterface */
        $connection = $this->resource->getConnection();
        $connection->insert($this->resource->getTableName(self::TABLE), $row);

        return (int)$connection->lastInsertId($this->resource->getTableName(self::TABLE));
    }

    /** @param array<string, mixed> $eventData */
    private function encodeEventData(array $eventData): ?string
    {
        $safe = [];
        foreach (['old', 'new'] as $key) {
            if (isset($eventData[$key]) && is_int($eventData[$key])) {
                $safe[$key] = $eventData[$key];
            }
        }
        if (isset($eventData['source']) && is_string($eventData['source']) && preg_match('/^[a-z_]{1,16}$/D', $eventData['source']) === 1) {
            $safe['source'] = $eventData['source'];
        }
        if ($safe === []) {
            return null;
        }
        $encoded = (string)$this->json->serialize($safe);

        return strlen($encoded) <= self::EVENT_DATA_LENGTH ? $encoded : null;
    }
}
