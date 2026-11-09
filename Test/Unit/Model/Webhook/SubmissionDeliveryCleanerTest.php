<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\SubmissionDeliveryCleaner;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

class SubmissionDeliveryCleanerTest extends TestCase
{
    public function testItDeletesDeliveriesInBoundedPrimaryKeyBatches(): void
    {
        $selects = [];
        $pages = [range(1, 1000), range(1001, 1500)];
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('beginTransaction');
        $connection->method('fetchCol')->willReturnCallback(function (string $sql, array $bind = []) use (&$selects, &$pages): array {
            $selects[] = [$sql, $bind];
            return array_shift($pages) ?? [];
        });
        $deletes = [];
        $connection->method('delete')->willReturnCallback(function (string $table, array $where) use (&$deletes): int {
            $deletes[] = [$table, $where];
            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $t): string => 'pfx_' . $t);

        (new SubmissionDeliveryCleaner($resource))->deleteFor([7, 8]);

        $this->assertCount(2, $selects);
        $this->assertSame(
            'SELECT delivery_id FROM pfx_furan_formbuilder_webhook_delivery WHERE submission_id IN (7,8) LIMIT 1000',
            $selects[0][0]
        );
        $this->assertStringNotContainsString('OFFSET', $selects[0][0]);
        $this->assertCount(2, $deletes);
        $this->assertSame(['pfx_furan_formbuilder_webhook_delivery', ['delivery_id IN (?)' => range(1, 1000)]], $deletes[0]);
        $this->assertSame(range(1001, 1500), $deletes[1][1]['delivery_id IN (?)']);
    }

    public function testItDoesNothingForNoIds(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');

        (new SubmissionDeliveryCleaner($resource))->deleteFor([]);
    }
}
