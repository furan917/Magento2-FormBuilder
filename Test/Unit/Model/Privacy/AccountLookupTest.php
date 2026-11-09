<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Privacy;

use Furan\FormBuilder\Model\Privacy\AccountLookup;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

class AccountLookupTest extends TestCase
{
    public function testItFindsEveryAccountUsingTheEmailAcrossWebsites(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('fetchCol')
            ->with('SELECT entity_id FROM customer_entity WHERE email = ? ORDER BY entity_id LIMIT 100', ['a@b.c'])
            ->willReturn(['7', '12']);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->assertSame([7, 12], (new AccountLookup($resource))->customerIds(' a@b.c '));
    }

    public function testItSkipsTheLookupForAnEmptyEmail(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');

        $this->assertSame([], (new AccountLookup($resource))->customerIds(' '));
    }
}
