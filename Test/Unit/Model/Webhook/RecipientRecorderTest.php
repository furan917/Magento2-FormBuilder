<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\RecipientRecorder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use PHPUnit\Framework\TestCase;

class RecipientRecorderTest extends TestCase
{
    public function testItRecordsEachRecipientOnlyOncePerSubmission(): void
    {
        $connection = $this->createMock(Mysql::class);
        $connection->expects($this->once())->method('insertOnDuplicate')->with(
            'furan_formbuilder_webhook_recipient',
            [
                'submission_id' => 21,
                'endpoint_id' => 7,
                'host' => 'crm.example.com',
                'first_delivered_at' => '2026-11-08 10:00:00',
            ],
            ['submission_id']
        );
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        (new RecipientRecorder($resource))->record(21, 7, 'crm.example.com', '2026-11-08 10:00:00');
    }
}
