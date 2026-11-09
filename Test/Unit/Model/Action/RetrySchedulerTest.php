<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Action;

use Furan\FormBuilder\Model\Action\RetryScheduler;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RetrySchedulerTest extends TestCase
{
    private const NOW = 1800000000;

    /** @var array<int, array{0: string, 1: array, 2: array}> */
    private array $writes = [];

    private function scheduler(): RetryScheduler
    {
        $this->writes = [];
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $data, array $fields): int {
                $this->writes[] = [$table, $data, $fields];
                return 1;
            }
        );
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new RetryScheduler($resource, static fn (): int => self::NOW);
    }

    /** @return array<string, array{0: int, 1: int}> */
    public static function backoffs(): array
    {
        return [
            'after attempt 1' => [1, 60],
            'after attempt 2' => [2, 300],
            'after attempt 3' => [3, 1800],
            'after attempt 4' => [4, 7200],
            'after attempt 5' => [5, 21600],
            'beyond the table' => [9, 21600],
        ];
    }

    #[DataProvider('backoffs')]
    public function testItSchedulesTheNextAttemptWithTheBackoffForThatAttemptNumber(int $failed, int $delay): void
    {
        $this->scheduler()->schedule(41, 'crm', $failed, 'RuntimeException', false);

        [$table, $row] = $this->writes[0];
        $this->assertSame('furan_formbuilder_action_retry', $table);
        $this->assertSame(41, $row['submission_id']);
        $this->assertSame('crm', $row['action_code']);
        $this->assertSame($failed + 1, $row['attempt']);
        $this->assertSame(gmdate('Y-m-d H:i:s', self::NOW + $delay), $row['due_at']);
        $this->assertSame('RuntimeException', $row['last_error']);
        $this->assertSame(0, $row['is_edit']);
    }

    public function testItKeepsOnePendingRetryPerSubmissionAndAction(): void
    {
        $this->scheduler()->schedule(41, 'crm', 1, str_repeat('x', 400), true);

        [, $row, $fields] = $this->writes[0];
        $this->assertSame(1, $row['is_edit']);
        $this->assertSame(['attempt', 'due_at', 'last_error'], array_slice($fields, 0, 3));
        $this->assertSame(255, strlen($row['last_error']));
        $schema = (string)file_get_contents(dirname(__DIR__, 3) . '/../etc/db_schema.xml');
        $this->assertMatchesRegularExpression(
            '~name="furan_formbuilder_action_retry".*?xsi:type="unique".*?submission_id.*?action_code"/>\s*</constraint>~s',
            $schema
        );
    }

    public function testAPendingCreateRetryStaysACreateWhenAnEditOfTheSameActionAlsoFails(): void
    {
        $this->scheduler()->schedule(41, 'crm', 1, 'RuntimeException', true);

        $merge = $this->writes[0][2]['is_edit'] ?? null;
        $this->assertInstanceOf(\Zend_Db_Expr::class, $merge);
        $this->assertSame('LEAST(is_edit, VALUES(is_edit))', (string)$merge);
    }
}
