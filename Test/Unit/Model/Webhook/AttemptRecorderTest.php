<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\AttemptRecorder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttemptRecorderTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array}> */
    private array $inserts = [];

    private function recorder(): AttemptRecorder
    {
        $connection = $this->createStub(Mysql::class);
        $connection->method('insert')->willReturnCallback(function (string $table, array $data): int {
            $this->inserts[] = [$table, $data];
            return 1;
        });
        $connection->method('lastInsertId')->willReturn('55');
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new AttemptRecorder($resource, static fn (): int => 1800000000);
    }

    public function testItRecordsEachAttemptSeparatelyFromTheDeliveryAndTheDebugExcerptSeparatelyAgain(): void
    {
        $recorder = $this->recorder();

        $id = $recorder->record(7, '2026-11-08 10:00:00', 120, 503, 'http', null, '203.0.113.9', "https://x.test", 60);

        $this->assertSame(55, $id);
        $this->assertCount(1, $this->inserts);
        [$table, $row] = $this->inserts[0];
        $this->assertSame('furan_formbuilder_webhook_attempt', $table);
        $this->assertSame(7, $row['delivery_id']);
        $this->assertSame(503, $row['status_code']);
        $this->assertSame('http', $row['error_class']);
        $this->assertSame('203.0.113.9', $row['connected_ip']);
        $this->assertSame('https://x.test', $row['location']);
        $this->assertSame(60, $row['retry_after']);
        $this->assertArrayNotHasKey('excerpt', $row);

        $recorder->record(7, '2026-11-08 10:00:01', 5, 200, null, null, null, null, null, "ok\x00\x1b[31m" . str_repeat('x', 2000));

        $this->assertCount(3, $this->inserts);
        [$debugTable, $debug] = $this->inserts[2];
        $this->assertSame('furan_formbuilder_webhook_attempt_debug', $debugTable);
        $this->assertSame(55, $debug['attempt_id']);
        $this->assertSame(1024, strlen($debug['excerpt']));
        $this->assertStringStartsWith('ok[31m', $debug['excerpt']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function locations(): array
    {
        return [
            'path and query dropped' => ['https://Hooks.Example.test/a/b?token=secret#x', 'https://hooks.example.test/…'],
            'bare host' => ['https://hooks.example.test', 'https://hooks.example.test'],
            'bare host with root path' => ['http://hooks.example.test/', 'http://hooks.example.test'],
            'port kept' => ['https://hooks.example.test:8443/x', 'https://hooks.example.test:8443/…'],
            'userinfo dropped' => ['https://user:pw@hooks.example.test/x', 'https://hooks.example.test/…'],
            'control bytes dropped' => ["https://x.test/a\r\nb", 'https://x.test/…'],
            'relative redirect' => ['/login?next=1', '…'],
            'scheme relative' => ['//x.test/a', '…'],
            'other scheme' => ['ftp://x.test/a', '…'],
            'garbage' => ['http:///', '…'],
        ];
    }

    #[DataProvider('locations')]
    public function testItStoresOnlyTheSchemeAndHostOfARedirectLocation(string $location, string $stored): void
    {
        $this->recorder()->record(7, '2026-11-08 10:00:00', 1, 302, 'http', null, null, $location, null);

        $this->assertSame($stored, $this->inserts[0][1]['location']);
    }

    public function testItStoresARedirectLocationWithInvalidUtf8Safely(): void
    {
        $this->recorder()->record(7, '2026-11-08 10:00:00', 1, 302, 'http', null, null, "https://x\xC3.test/\xE2\x82", null);
        $this->recorder()->record(7, '2026-11-08 10:00:00', 1, 302, 'http', null, null, 'https://' . str_repeat('h', 300) . '.test/x', null);

        foreach ($this->inserts as [, $row]) {
            $this->assertTrue(mb_check_encoding($row['location'], 'UTF-8'));
            $this->assertLessThanOrEqual(255, mb_strlen($row['location'], 'UTF-8'));
        }
        $this->assertSame('https://x.test', $this->inserts[0][1]['location']);
        $this->assertStringEndsWith('/…', $this->inserts[1][1]['location']);
    }

    public function testItStoresADebugExcerptWithMultibyteTextCutMidCharacterAsValidUtf8(): void
    {
        $this->recorder()->record(7, '2026-11-08 10:00:00', 5, 200, null, null, null, null, null, 'x' . str_repeat('é', 600));

        $excerpt = $this->inserts[1][1]['excerpt'];
        $this->assertTrue(mb_check_encoding($excerpt, 'UTF-8'));
        $this->assertSame(1023, strlen($excerpt));
    }

    public function testItScrubsInvalidBytesFromADebugExcerpt(): void
    {
        $this->recorder()->record(7, '2026-11-08 10:00:00', 5, 200, null, null, null, null, null, "ok\xFF\xFEend");

        $this->assertTrue(mb_check_encoding($this->inserts[1][1]['excerpt'], 'UTF-8'));
    }
}
