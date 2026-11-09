<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Customer;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Customer\CustomerSummary;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\Module\Manager;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class CustomerSummaryTest extends TestCase
{
    private Mysql&Stub $connection;
    /** @var array<int, array{string, array}> */
    private array $calls = [];
    private bool $salesEnabled = true;
    private bool $ordersTableExists = true;

    private function summary(): CustomerSummary
    {
        $this->connection = $this->createStub(Mysql::class);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $select = $this->createStub(Select::class);
        foreach (['from', 'joinLeft', 'where', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($method, $select) {
                while ($args !== [] && end($args) === null) {
                    array_pop($args);
                }
                $this->calls[] = [$method, $args];
                return $select;
            });
        }
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('isTableExists')->willReturnCallback(
            fn (string $table): bool => $table === 'sales_order' && $this->ordersTableExists
        );
        $modules = $this->createStub(Manager::class);
        $modules->method('isEnabled')->willReturnCallback(fn (string $module): bool => $module === 'Magento_Sales' && $this->salesEnabled);

        return new CustomerSummary($resource, $modules);
    }

    private function submission(?int $customerId, array $payload = [], int $id = 5): SubmissionInterface
    {
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getId')->willReturn($id);
        $submission->method('getCustomerId')->willReturn($customerId);
        $submission->method('getPayload')->willReturn($payload);

        return $submission;
    }

    private function called(string $method): array
    {
        return array_values(array_map(
            static fn(array $call): array => $call[1],
            array_filter($this->calls, static fn(array $call): bool => $call[0] === $method)
        ));
    }

    public function testItShowsTheCustomersNameGroupAndEmail(): void
    {
        $summary = $this->summary();
        $this->connection->method('fetchRow')->willReturn(
            ['firstname' => 'Ann', 'lastname' => 'Lee', 'email' => 'ann@example.com', 'group' => 'Wholesale']
        );
        $this->connection->method('fetchAll')->willReturn([]);

        $card = $summary->card($this->submission(12), [], false);

        $this->assertSame('customer', $card['type']);
        $this->assertSame('Ann Lee', $card['name']);
        $this->assertSame('Wholesale', $card['group']);
        $this->assertSame('ann@example.com', $card['email']);
        $this->assertContains(['c.entity_id = ?', 12], $this->called('where'));
    }

    public function testItCountsOrdersAndSumsLifetimeSpendExcludingCancelledOrders(): void
    {
        $summary = $this->summary();
        $this->connection->method('fetchRow')->willReturnCallback(
            static fn($select): array => ['orders' => '3', 'lifetime' => '150.5000', 'last_order_at' => '2026-02-01 10:00:00']
        );

        $stats = $summary->orderStats(12);

        $this->assertSame(['count' => 3, 'lifetime' => 150.5, 'lastOrderAt' => '2026-02-01 10:00:00'], $stats);
        $this->assertContains(['customer_id = ?', 12], $this->called('where'));
        $this->assertContains(['state <> ?', 'canceled'], $this->called('where'));
        $from = $this->called('from')[0];
        $this->assertSame('sales_order', $from[0]);
        $this->assertSame('SUM(base_grand_total * base_to_global_rate)', (string)$from[1]['lifetime']);
    }

    public function testItListsTheCustomersOtherSubmissionsNewestFirst(): void
    {
        $summary = $this->summary();
        $rows = [['submission_id' => 9, 'form_id' => 1, 'summary' => 'x', 'created_at' => '2026-01-01 00:00:00', 'status' => 0]];
        $this->connection->method('fetchAll')->willReturn($rows);

        $this->assertSame($rows, $summary->otherSubmissions(12, 5));
        $this->assertContains(['customer_id = ?', 12], $this->called('where'));
        $this->assertContains(['submission_id <> ?', 5], $this->called('where'));
        $this->assertSame([['submission_id DESC']], $this->called('order'));
        $this->assertSame([[10]], $this->called('limit'));
    }

    public function testItShowsAGuestCardWithTheRespondentEmailForGuests(): void
    {
        $summary = $this->summary();
        $payload = [
            ['name' => 'other', 'label' => 'O', 'type' => 'text', 'value' => 'nope@example.com'],
            ['name' => 'mail', 'label' => 'M', 'type' => 'email', 'value' => 'guest@example.com'],
        ];

        $card = $summary->card($this->submission(null, $payload), ['autoresponder_email_field' => 'mail'], true);

        $this->assertSame('guest', $card['type']);
        $this->assertSame('guest@example.com', $card['email']);
        $this->assertNull($card['orders']);
        $this->assertSame([], $card['others']);
        $this->assertSame([], $this->calls);
    }

    public function testItGivesAGuestCardWithoutEmailWhenTheAnswerIsNotAnAddress(): void
    {
        $summary = $this->summary();
        $payload = [['name' => 'mail', 'label' => 'M', 'type' => 'email', 'value' => "a@b.co\nBcc: x@y.zz"]];

        $card = $summary->card($this->submission(0, $payload), ['email_field' => 'mail'], false);

        $this->assertSame('', $card['email']);
    }

    public function testItHidesOrderFiguresWithoutTheSalesOrderPermission(): void
    {
        $summary = $this->summary();
        $this->connection->method('fetchRow')->willReturn(['firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.co', 'group' => 'G']);
        $this->connection->method('fetchAll')->willReturn([]);

        $card = $summary->card($this->submission(12), [], false);

        $this->assertNull($card['orders']);
        $this->assertNotContains('sales_order', array_column($this->called('from'), 0));
    }

    public function testItIncludesOrderFiguresWhenPermitted(): void
    {
        $summary = $this->summary();
        $this->connection->method('fetchRow')->willReturn(
            ['firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.co', 'group' => 'G', 'orders' => '0', 'lifetime' => null, 'last_order_at' => null]
        );
        $this->connection->method('fetchAll')->willReturn([]);

        $card = $summary->card($this->submission(12), [], true);

        $this->assertSame(['count' => 0, 'lifetime' => 0.0, 'lastOrderAt' => null], $card['orders']);
    }

    public function testItLeavesOrderFiguresOutWhenTheSalesModuleIsDisabled(): void
    {
        $this->salesEnabled = false;
        $summary = $this->summary();
        $this->connection->method('fetchRow')->willReturn(['firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.co', 'group' => 'G']);
        $this->connection->method('fetchAll')->willReturn([]);

        $card = $summary->card($this->submission(12), [], true);

        $this->assertSame('customer', $card['type']);
        $this->assertNull($card['orders']);
        $this->assertNotContains('sales_order', array_column($this->called('from'), 0));
    }

    public function testItLeavesOrderFiguresOutWhenTheOrderTableIsMissing(): void
    {
        $this->ordersTableExists = false;
        $summary = $this->summary();

        $this->assertNull($summary->orderStats(12));
        $this->assertSame([], $this->called('from'));
    }

    public function testItShowsTheStoredProductContextWithALinkToTheProduct(): void
    {
        $context = $this->summary()->productContext([
            'id' => 4, 'sku' => 'SKU-1', 'name' => 'Lamp', 'url' => 'https://shop.test/lamp.html', 'price' => 9.5, 'currency' => 'EUR',
        ]);

        $this->assertSame('Lamp', $context['name']);
        $this->assertSame('SKU-1', $context['sku']);
        $this->assertSame('https://shop.test/lamp.html', $context['url']);
        $this->assertSame(9.5, $context['price']);
        $this->assertSame('EUR', $context['currency']);
    }

    public function testItDropsProductLinksThatAreNotHttp(): void
    {
        $summary = $this->summary();

        $this->assertSame('', $summary->productContext(['name' => 'X', 'url' => 'javascript:alert(1)'])['url']);
        $this->assertNull($summary->productContext(null));
        $this->assertNull($summary->productContext([]));
    }
}
