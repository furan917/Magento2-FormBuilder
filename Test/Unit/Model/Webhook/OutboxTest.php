<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\DeliveryStatus;
use Furan\FormBuilder\Model\Webhook\EndpointResolver;
use Furan\FormBuilder\Model\Webhook\EndpointRoute;
use Furan\FormBuilder\Model\Webhook\Outbox;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OutboxTest extends TestCase
{
    private const NOW = 1800000000;

    /** @var array<int, array<int, array<string, mixed>>> */
    private array $multi = [];

    /** @var array<int, array{0: string, 1: array}> */
    private array $inserts = [];

    /** @var array<int, array{0: string, 1: string}> */
    private array $published = [];

    private bool $publishFails = false;

    private int $fetches = 0;

    private Mysql $connection;

    /** @var EndpointRoute[] */
    private array $matches = [];

    /** @var array<string, mixed>|false */
    private $originalRow = false;

    private LoggerInterface&\PHPUnit\Framework\MockObject\Stub $logger;

    private function outbox(): Outbox
    {
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->connection = $this->createMock(Mysql::class);
        $this->connection->expects($this->never())->method('beginTransaction');
        $this->connection->expects($this->never())->method('commit');
        $this->connection->expects($this->never())->method('rollBack');
        $this->connection->method('insertMultiple')->willReturnCallback(function (string $table, array $rows): int {
            $this->multi[] = $rows;
            return count($rows);
        });
        $this->connection->method('insert')->willReturnCallback(function (string $table, array $data): int {
            $this->inserts[] = [$table, $data];
            return 1;
        });
        $this->connection->method('lastInsertId')->willReturn('41');
        $this->connection->method('fetchCol')->willReturnCallback(function (): array {
            $this->fetches++;
            $ids = [];
            $before = array_sum(array_map(count(...), array_slice($this->multi, 0, -1)));
            foreach (array_keys((array)end($this->multi)) as $i) {
                $ids[] = (string)(100 + $before + $i);
            }
            return $ids;
        });
        $this->connection->method('fetchRow')->willReturnCallback(fn () => $this->originalRow);
        $select = $this->createStub(\Magento\Framework\DB\Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $resolver = $this->createStub(EndpointResolver::class);
        $resolver->method('match')->willReturnCallback(fn (): array => $this->matches);

        $publisher = $this->createStub(PublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function (string $topic, $message): void {
            if ($this->publishFails) {
                throw new \RuntimeException('broker down');
            }
            $this->published[] = [$topic, $message];
        });

        return new Outbox($resource, $resolver, new Json(), $publisher, $this->logger, static fn (): int => self::NOW);
    }

    private function view(int $id): EndpointRoute
    {
        $view = $this->createStub(EndpointRoute::class);
        $view->method('id')->willReturn($id);

        return $view;
    }

    public function testItWritesOneDeliveryPerMatchingEndpoint(): void
    {
        $outbox = $this->outbox();
        $this->matches = [$this->view(3), $this->view(4)];

        $ids = $outbox->record(9, 5, 1, 'submission.created');

        $this->assertSame([100, 101], $ids);
        $this->assertCount(1, $this->multi);
        $this->assertSame([3, 4], array_column($this->multi[0], 'endpoint_id'));
        $row = $this->multi[0][0];
        $this->assertSame(9, $row['submission_id']);
        $this->assertSame('submission.created', $row['event']);
        $this->assertSame(DeliveryStatus::PENDING, $row['status']);
        $this->assertSame(0, $row['attempts']);
        $this->assertSame(gmdate('Y-m-d H:i:s', self::NOW), $row['next_attempt_at']);
    }

    /** @return array<int, array<string, mixed>> */
    private static function items(int $count): array
    {
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = ['submissionId' => $i, 'formId' => 5, 'storeId' => 1, 'eventData' => ['old' => $i % 3, 'new' => 2]];
        }

        return $items;
    }

    public function testItWritesTheOutboxRowsForABatchOfSubmissionsInOneInsertPerChunk(): void
    {
        $outbox = $this->outbox();
        $this->matches = [$this->view(3), $this->view(4)];

        $ids = $outbox->recordMany('submission.status_changed', self::items(1200));

        $this->assertCount(3, $this->multi);
        $this->assertSame([1000, 1000, 400], array_map(count(...), $this->multi));
        $this->assertSame(2400, count($ids));
        $this->assertSame(2400, count(array_unique(array_merge(...array_map(
            static fn (array $rows): array => array_column($rows, 'webhook_id'),
            $this->multi
        )))));
    }

    public function testItReadsTheBatchBackWithOneQueryPerChunk(): void
    {
        $outbox = $this->outbox();
        $this->matches = [$this->view(3)];

        $outbox->recordMany('submission.status_changed', self::items(2500));

        $this->assertSame(3, $this->fetches);
        $this->assertSame([], $outbox->recordMany('submission.status_changed', []));
    }

    public function testItKeepsEachSubmissionsOwnOldAndNewStatusInTheBatch(): void
    {
        $outbox = $this->outbox();
        $this->matches = [$this->view(3)];

        $outbox->recordMany('submission.status_changed', self::items(3));

        $this->assertSame(
            ['{"old":1,"new":2}', '{"old":2,"new":2}', '{"old":0,"new":2}'],
            array_column($this->multi[0], 'event_data')
        );
        $this->assertSame([1, 2, 3], array_column($this->multi[0], 'submission_id'));
    }

    public function testItWritesNoDeliveryWhenNoEndpointMatches(): void
    {
        $outbox = $this->outbox();
        $this->matches = [];

        $this->assertSame([], $outbox->record(9, 5, 1, 'submission.created'));
        $this->assertSame([], $this->multi);
    }

    public function testItNeverOpensOrCommitsATransactionItself(): void
    {
        $outbox = $this->outbox();
        $this->matches = [$this->view(3)];

        $outbox->record(9, 5, 1, 'submission.created');
        $outbox->recordTest(3, 1);
        $this->originalRow = ['endpoint_id' => '3', 'submission_id' => '9', 'event' => 'submission.created', 'event_data' => null, 'webhook_id' => 'msg_x'];
        $outbox->recordReplay(100, 1, true);

        $this->assertNotEmpty($this->multi);
        $this->assertCount(2, $this->inserts);
    }

    public function testItStoresStatusChangeDetailsWithoutPersonalData(): void
    {
        $outbox = $this->outbox();
        $this->matches = [$this->view(3)];

        $outbox->record(9, 5, 1, 'submission.status_changed', [
            'old' => 0, 'new' => 2, 'source' => 'admin', 'email' => 'jo@example.com', 'note' => 'call me',
        ]);

        $this->assertSame('{"old":0,"new":2,"source":"admin"}', $this->multi[0][0]['event_data']);
    }

    public function testItPublishesAWakeUpMessageCarryingDeliveryIdsOnly(): void
    {
        $outbox = $this->outbox();

        $outbox->wake(range(1, 150));
        $outbox->wake([]);

        $this->assertCount(2, $this->published);
        $this->assertSame('furan_formbuilder.webhook', $this->published[0][0]);
        $this->assertSame(['ids' => range(1, 100)], json_decode($this->published[0][1], true));
        $this->assertSame(['ids' => range(101, 150)], json_decode($this->published[1][1], true));
    }

    public function testItLogsOnlyTheExceptionClassWhenTheWakeUpFails(): void
    {
        $outbox = $this->outbox();
        $this->publishFails = true;
        $logged = [];
        $this->logger->method('warning')->willReturnCallback(function (string $message, array $context = []) use (&$logged): void {
            $logged[] = $message . json_encode($context);
        });

        $outbox->wake([1, 2]);

        $this->assertCount(1, $logged);
        $this->assertStringNotContainsString('broker down', $logged[0]);
        $this->assertStringContainsString('RuntimeException', $logged[0]);
        $this->assertStringContainsString('2', $logged[0]);
    }

    public function testItSwallowsAWakeUpPublishFailure(): void
    {
        $outbox = $this->outbox();
        $this->publishFails = true;

        $outbox->wake([1]);

        $this->assertSame([], $this->published);
    }

    public function testItGivesEveryDeliveryAMsgWebhookId(): void
    {
        $outbox = $this->outbox();
        $this->matches = [$this->view(3), $this->view(4)];

        $outbox->record(9, 5, 1, 'submission.created');
        $outbox->recordTest(3, 1);

        $first = $this->multi[0][0]['webhook_id'];
        $second = $this->multi[0][1]['webhook_id'];
        $this->assertMatchesRegularExpression('/^msg_[0-9a-f]{32}$/D', $first);
        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^msg_[0-9a-f]{32}$/D', $this->inserts[0][1]['webhook_id']);
        $this->assertSame('webhook.test', $this->inserts[0][1]['event']);
        $this->assertNull($this->inserts[0][1]['submission_id']);
        $this->assertSame(1, $this->inserts[0][1]['admin_user_id']);
    }

    public function testItReplaysADeliveryWithItsOwnOrANewWebhookId(): void
    {
        $outbox = $this->outbox();
        $this->originalRow = [
            'endpoint_id' => '3', 'submission_id' => '9', 'event' => 'submission.status_changed',
            'event_data' => '{"old":0,"new":2}', 'webhook_id' => 'msg_' . str_repeat('a', 32),
        ];

        $outbox->recordReplay(100, 7, false);
        $outbox->recordReplay(100, 7, true);

        $same = $this->inserts[0][1];
        $new = $this->inserts[1][1];
        $this->assertSame('msg_' . str_repeat('a', 32), $same['webhook_id']);
        $this->assertNotSame($same['webhook_id'], $new['webhook_id']);
        $this->assertSame(100, $same['replay_of']);
        $this->assertSame(7, $same['admin_user_id']);
        $this->assertSame(3, $same['endpoint_id']);
        $this->assertSame(9, $same['submission_id']);
        $this->assertSame('{"old":0,"new":2}', $same['event_data']);
        $this->assertSame(DeliveryStatus::PENDING, $same['status']);
    }
}
