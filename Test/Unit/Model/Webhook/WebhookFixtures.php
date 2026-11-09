<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\Audit;
use Furan\FormBuilder\Model\Webhook\EndpointInput;
use Furan\FormBuilder\Model\Webhook\EndpointRepository;
use Furan\FormBuilder\Model\Webhook\Secrets;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;

/** Builds the webhook storage classes on a recording connection. */
class WebhookFixtures
{
    public const NOW = 1800000000;

    /** @var array<int, array{0: string, 1: array}> */
    public array $inserts = [];

    /** @var array<int, array{0: string, 1: array, 2: mixed}> */
    public array $updates = [];

    /** @var array<int, array<int, mixed>> columns passed to Select::from() */
    public array $selectedColumns = [];

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public ?int $updateAffects = 1;

    public Mysql $connection;

    public EndpointRepository $repository;

    public Secrets $secrets;

    public Audit $audit;

    /** @param callable(class-string): object $mock */
    public function __construct(callable $mock)
    {
        $this->connection = $mock(Mysql::class);
        $this->connection->method('insert')->willReturnCallback(function (string $table, array $data): int {
            $this->inserts[] = [$table, $data];
            return 1;
        });
        $this->connection->method('lastInsertId')->willReturn('7');
        $this->connection->method('update')->willReturnCallback(function (string $table, array $bind, $where): int {
            $this->updates[] = [$table, $bind, $where];
            return (int)$this->updateAffects;
        });
        $select = $mock(Select::class);
        $select->method('from')->willReturnCallback(function ($t, $cols = '*') use ($select) {
            $this->selectedColumns[] = (array)$cols;
            return $select;
        });
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchAll')->willReturnCallback(fn (): array => $this->rows);
        $this->connection->method('fetchRow')->willReturnCallback(fn () => $this->rows[0] ?? false);
        $resource = $mock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $encryptor = $mock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(static fn (string $v): string => 'enc[' . $v . ']');
        $encryptor->method('decrypt')->willReturnCallback(
            static fn (string $v): string => preg_match('/^enc\[(.*)\]$/s', $v, $m) ? $m[1] : ''
        );
        $json = new Json();
        $this->secrets = new Secrets($encryptor);
        $this->audit = new Audit($resource);
        $this->repository = new EndpointRepository($resource, $json, $this->secrets, $this->audit, new EndpointInput(), static fn (): int => self::NOW);
    }

    /** @return array<string, mixed> */
    public static function input(array $override = []): array
    {
        return $override + [
            'name' => 'CRM',
            'url' => 'https://crm.example.com/hooks/in',
            'is_active' => true,
            'scope_type' => 'store',
            'scope_ids' => [1, 2],
            'all_forms' => false,
            'form_ids' => [5, 6],
            'events' => ['submission.created', 'submission.updated'],
            'field_mode' => 'all',
            'fields' => [],
            'include_personal' => false,
            'debug_responses' => false,
        ];
    }

    /** @return array<string, mixed>|null the row written to the endpoint table */
    public function endpointInsert(): ?array
    {
        foreach ($this->inserts as [$table, $data]) {
            if ($table === 'furan_formbuilder_webhook') {
                return $data;
            }
        }
        return null;
    }

    /** @return array<string, mixed> a stored endpoint row as the readers select it */
    public static function row(array $override = []): array
    {
        return $override + [
            'endpoint_id' => '7',
            'name' => 'CRM',
            'url' => 'https://crm.example.com/hooks/in',
            'is_active' => '1',
            'scope_type' => 'store',
            'scope_ids' => '[1,2]',
            'all_forms' => '0',
            'form_ids' => '[5,6]',
            'events' => '["submission.created"]',
            'field_mode' => 'all',
            'fields' => '[]',
            'include_personal' => '0',
            'debug_responses' => '0',
            'previous_expires_at' => null,
            'disabled_reason' => null,
            'failing_since' => null,
            'consecutive_failures' => '0',
            'circuit_open_until' => null,
        ];
    }
}
