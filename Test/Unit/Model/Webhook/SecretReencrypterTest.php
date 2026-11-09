<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\SecretReencrypter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

class SecretReencrypterTest extends TestCase
{
    private const NEWEST = '2';

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    /** @var array<int, array{0: array, 1: array}> */
    private array $updates = [];

    private SecretReencrypter $reencrypter;

    protected function setUp(): void
    {
        $connection = $this->createStub(Mysql::class);
        $connection->method('fetchAll')->willReturnCallback(function (string $sql, array $bind = []): array {
            $this->assertStringNotContainsString('OFFSET', $sql);
            $rows = array_filter($this->rows, static fn(array $r): bool => (int)$r['endpoint_id'] > (int)$bind[':last']);
            return array_slice(array_values($rows), 0, 2);
        });
        $connection->method('update')->willReturnCallback(function (string $table, array $bind, $where): int {
            $this->updates[] = [$bind, $where];
            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(
            static fn(string $v): string => self::NEWEST . ':3:' . base64_encode($v)
        );
        $encryptor->method('decrypt')->willReturnCallback(static function (string $v): string {
            $parts = explode(':', $v, 3);
            return ($parts[0] ?? '') === '9' || count($parts) !== 3 ? '' : (string)base64_decode($parts[2]);
        });
        $this->reencrypter = new SecretReencrypter($resource, $encryptor);
    }

    private function row(int $id, string $secret, ?string $previous = null): array
    {
        return ['endpoint_id' => $id, 'secret_enc' => $secret, 'previous_secret_enc' => $previous];
    }

    private function old(string $plain, string $version = '0'): string
    {
        return $version . ':3:' . base64_encode($plain);
    }

    public function testItReEncryptsCurrentAndPreviousSecretsWithTheNewestKey(): void
    {
        $this->rows = [
            $this->row(1, $this->old('whsec_a'), $this->old('whsec_b', '1')),
            $this->row(2, $this->old('whsec_c')),
            $this->row(3, $this->old('whsec_d')),
        ];

        $stats = $this->reencrypter->run(2);

        $this->assertSame(3, $stats['scanned']);
        $this->assertSame(3, $stats['reencrypted']);
        $this->assertSame([], $stats['failed']);
        $this->assertCount(3, $this->updates);
        $this->assertSame(
            ['secret_enc' => '2:3:' . base64_encode('whsec_a'), 'previous_secret_enc' => '2:3:' . base64_encode('whsec_b')],
            $this->updates[0][0]
        );
        $this->assertSame(['secret_enc' => '2:3:' . base64_encode('whsec_c')], $this->updates[1][0]);
        $this->assertSame(1, $this->updates[0][1]['endpoint_id = ?']);
        $this->assertSame($this->old('whsec_a'), $this->updates[0][1]['secret_enc = ?']);
    }

    public function testItSkipsSecretsAlreadyOnTheNewestKey(): void
    {
        $this->rows = [$this->row(1, $this->old('whsec_a', '2'), $this->old('whsec_b', '2'))];

        $stats = $this->reencrypter->run(2);

        $this->assertSame([], $this->updates);
        $this->assertSame(0, $stats['reencrypted']);
        $this->assertSame(1, $stats['skipped']);
    }

    public function testItReEncryptsSecretsOnAnOlderCipherVersion(): void
    {
        $this->rows = [$this->row(1, '2:2:' . base64_encode('whsec_a'), $this->old('whsec_b', '2'))];

        $stats = $this->reencrypter->run(2);

        $this->assertSame(1, $stats['reencrypted']);
        $this->assertSame(['secret_enc' => '2:3:' . base64_encode('whsec_a')], $this->updates[0][0]);
    }

    public function testItReportsUndecryptableSecretsWithoutChangingThemAndExitsWithAnError(): void
    {
        $this->rows = [
            $this->row(1, $this->old('whsec_a', '9')),
            $this->row(2, $this->old('not-a-secret')),
            $this->row(3, $this->old('whsec_ok'), $this->old('whsec_x', '9')),
        ];

        $stats = $this->reencrypter->run(2);

        $this->assertSame([1, 2, 3], $stats['failed']);
        $this->assertSame(1, $stats['reencrypted']);
        $this->assertCount(1, $this->updates);
        $this->assertSame(['secret_enc' => '2:3:' . base64_encode('whsec_ok')], $this->updates[0][0]);

        $command = new \Furan\FormBuilder\Console\Command\WebhookReencryptCommand($this->createConfiguredStub(
            SecretReencrypter::class,
            ['run' => $stats]
        ));
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('1, 2, 3', $tester->getDisplay());
        $this->assertStringNotContainsString('whsec_', $tester->getDisplay());
    }
}
