<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Account;

use Furan\FormBuilder\Model\Account\SubmissionReader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SubmissionReaderTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array}> */
    private array $calls = [];
    private AdapterInterface&Stub $connection;
    private SubmissionReader $reader;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?AdapterInterface $connection = null): void
    {
        $this->calls = [];
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($method, $select) {
                while ($args && end($args) === null) {
                    array_pop($args);
                }
                $this->calls[] = [$method, $args];
                return $select;
            });
        }
        $this->connection = $connection ?? $this->createStub(AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $t): string => $t);
        $this->reader = new SubmissionReader($resource);
    }

    private function callsOf(string $method): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === $method));
    }

    public function testItListsOnlyTheCustomersOwnSubmissionsNewestFirst(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);
        $this->reader->listPage(7, 0, 20);

        $wheres = array_column(array_column($this->callsOf('where'), 1), 0);
        $this->assertContains('customer_id = ?', $wheres);
        $this->assertSame([['customer_id = ?', 7]], array_map(
            static fn (array $c): array => $c[1],
            array_values(array_filter($this->callsOf('where'), static fn (array $c): bool => $c[1][0] === 'customer_id = ?'))
        ));
        $this->assertSame([['submission_id DESC']], array_column($this->callsOf('order'), 1));
        $this->assertSame(21, $this->callsOf('limit')[0][1][0]);
        $columns = $this->callsOf('from')[0][1][1];
        $this->assertSame(['submission_id', 'form_id', 'status', 'summary', 'created_at', 'updated_at', 'reference'], $columns);
    }

    public function testItPagesByLastSeenId(): void
    {
        $this->connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            array_map(static fn (int $id): array => ['submission_id' => (string)$id, 'form_id' => '1'], [30, 29, 28]),
            [['form_id' => '1', 'title' => 'Contact']]
        );
        $page = $this->reader->listPage(7, 31, 2);

        $this->assertContains(['submission_id < ?', 31], array_column($this->callsOf('where'), 1));
        $this->assertSame(3, $this->callsOf('limit')[0][1][0]);
        $this->assertSame([30, 29], array_map(static fn (array $r): int => (int)$r['submission_id'], $page['rows']));
        $this->assertSame(29, $page['older']);
        $this->assertSame('Contact', $page['rows'][0]['form_title']);
    }

    public function testItOmitsTheCursorOnTheFirstPageAndHasNoOlderLinkOnTheLast(): void
    {
        $this->connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [['submission_id' => '5', 'form_id' => '1']],
            [['form_id' => '1', 'title' => 'T']]
        );
        $page = $this->reader->listPage(7, 0, 20);

        $wheres = array_column(array_column($this->callsOf('where'), 1), 0);
        $this->assertNotContains('submission_id < ?', $wheres);
        $this->assertNull($page['older']);
    }

    public function testItNeverQueriesWithoutACustomerId(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $connection->expects($this->never())->method('fetchAll');
        $connection->expects($this->never())->method('fetchRow');
        $this->assertSame(['rows' => [], 'older' => null], $this->reader->listPage(0, 0, 20));
        $this->assertNull($this->reader->find(0, 5));
        $this->assertNull($this->reader->find(5, 0));
    }

    public function testItFindsOneOwnRowWithoutContextAndMemoisesIt(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->fixture(connection: $connection);
        $connection->expects($this->once())->method('fetchRow')
            ->willReturn(['submission_id' => '9', 'form_id' => '1', 'payload_json' => '[]']);
        $this->assertSame('9', $this->reader->find(7, 9)['submission_id']);
        $this->reader->find(7, 9);

        $wheres = array_column(array_column($this->callsOf('where'), 1), 0);
        $this->assertContains('customer_id = ?', $wheres);
        $this->assertContains('submission_id = ?', $wheres);
        $this->assertNotContains('context_json', $this->callsOf('from')[0][1][1]);
        $this->assertContains('payload_json', $this->callsOf('from')[0][1][1]);
    }

    public function testItReturnsTheReferenceAsAHyphenatedStringNeverRawBytes(): void
    {
        $bytes = hex2bin('0194f3a0111172228333444455556666');
        $this->connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [['submission_id' => '5', 'form_id' => '1', 'reference' => $bytes]],
            [['form_id' => '1', 'title' => 'T']]
        );
        $this->connection->method('fetchRow')
            ->willReturn(['submission_id' => '5', 'form_id' => '1', 'reference' => $bytes, 'payload_json' => '[]']);

        $page = $this->reader->listPage(7, 0, 20);
        $row = $this->reader->find(7, 5);

        $this->assertSame('0194f3a0-1111-7222-8333-444455556666', $page['rows'][0]['reference']);
        $this->assertSame('0194f3a0-1111-7222-8333-444455556666', $row['reference']);
        $this->assertNotFalse(json_encode($page));
    }
}
