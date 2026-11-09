<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Privacy;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Privacy\DeletedAccountQueue;
use Furan\FormBuilder\Model\Survey\Respondents;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Statement\Pdo\Mysql as Statement;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class DeletedAccountQueueTest extends TestCase
{
    /** @var list<array> ordered statements */
    private array $log = [];
    /** @var list<array{string, string}> */
    private array $published = [];
    private int $inserted = 2;
    private bool $publishFails = false;

    private function queue(string $behaviour): DeletedAccountQueue
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $bind): string {
            $this->log[] = ['fetchOne', $sql, $bind];
            return (string)$bind[0];
        });
        $connection->method('update')->willReturnCallback(function (string $table, array $data, array $where): int {
            $this->log[] = ['update', $table, $data, $where];
            return 1;
        });
        $connection->method('query')->willReturnCallback(function (string $sql, array $bind) {
            $this->log[] = ['query', $sql, $bind];
            $statement = $this->createStub(Statement::class);
            $statement->method('rowCount')->willReturn($this->inserted);
            return $statement;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $config = $this->createStub(Config::class);
        $config->method('getAccountDeletionBehaviour')->willReturn($behaviour);
        $respondents = $this->createStub(Respondents::class);
        $respondents->method('customerKey')->willReturnCallback(static fn (int $id): string => 'c:' . $id);
        $respondents->method('removeByKeys')->willReturnCallback(function (array $keys): int {
            $this->log[] = ['removeByKeys', $keys];
            return 1;
        });
        $publisher = $this->createStub(PublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function (string $topic, string $message): void {
            if ($this->publishFails) {
                throw new \RuntimeException('down');
            }
            $this->published[] = [$topic, $message];
        });

        return new DeletedAccountQueue($resource, $config, $publisher, new Json(), $respondents);
    }

    public static function erasingBehaviours(): array
    {
        return [['delete'], ['anonymise']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('erasingBehaviours')]
    public function testItRecordsTheAccountsSubmissionsDurablyInsideTheDeleteTransaction(string $behaviour): void
    {
        $this->queue($behaviour)->capture(9);

        $this->assertCount(4, $this->log);
        [, $lockSql, $lockBind] = $this->log[0];
        $this->assertSame('SELECT entity_id FROM customer_entity WHERE entity_id = ? FOR UPDATE', $lockSql);
        $this->assertSame([9], $lockBind);
        $this->assertSame(
            ['update', 'furan_formbuilder_submission', ['email_hash' => null, 'email_hash_version' => null], ['customer_id = ?' => 9]],
            $this->log[1]
        );
        [, $sql, $bind] = $this->log[2];
        $this->assertSame(
            'INSERT INTO furan_formbuilder_privacy_pending (submission_id, customer_id, mode, next_attempt_at)'
            . ' SELECT submission_id, ?, ?, ? FROM furan_formbuilder_submission WHERE customer_id = ?',
            $sql
        );
        $this->assertSame(9, $bind[0]);
        $this->assertSame($behaviour, $bind[1]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $bind[2]);
        $this->assertSame(9, $bind[3]);
        $this->assertSame(['removeByKeys', ['c:9']], $this->log[3]);
        $this->assertSame([], $this->published, 'nothing is published inside the delete transaction');
    }

    public function testItLeavesTheRowsUntouchedWhenSubmissionsAreKept(): void
    {
        $queue = $this->queue('keep');
        $queue->capture(9);
        $queue->publish(9);

        $this->assertSame([], $this->log);
        $this->assertSame([], $this->published);
    }

    public function testItPublishesOnlyAWakeUpWithTheCustomerIdAfterCommit(): void
    {
        $queue = $this->queue('anonymise');
        $queue->capture(9);
        $queue->publish(9);
        $queue->publish(9);

        $this->assertSame([[DeletedAccountQueue::TOPIC, '{"customer_id":9}']], $this->published);
    }

    public function testItPublishesNothingForAnAccountWithoutSubmissions(): void
    {
        $this->inserted = 0;
        $queue = $this->queue('delete');
        $queue->capture(9);
        $queue->publish(9);

        $this->assertSame([], $this->published);
        $this->assertContains(['removeByKeys', ['c:9']], $this->log);
    }

    public function testItPublishesEachCapturedAccountOnlyAfterItsOwnCommit(): void
    {
        $queue = $this->queue('delete');
        $queue->capture(9);
        $queue->capture(10);
        $queue->publish(10);
        $queue->publish(11);

        $this->assertSame([[DeletedAccountQueue::TOPIC, '{"customer_id":10}']], $this->published);
    }

    public function testItLetsAFailedPublishReachTheCallerWithoutLosingThePendingRows(): void
    {
        $this->publishFails = true;
        $queue = $this->queue('delete');
        $queue->capture(9);

        $this->expectExceptionMessage('down');
        $queue->publish(9);
    }
}
