<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Model\Submission\EmailResolver;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class EmailResolverTest extends TestCase
{
    private int $customerQueries = 0;

    public function testItResolvesEmailsFromTheAccountOrTheFormsEmailField(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchAll')->willReturnCallback(function (string $sql, array $bind) {
            $this->customerQueries++;
            return [['entity_id' => 9, 'email' => 'a@example.com']];
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $resolver = new EmailResolver($resource, new Json());
        $rows = [
            ['submission_id' => 1, 'form_id' => 1, 'customer_id' => 9, 'payload_json' => '[]'],
            ['submission_id' => 2, 'form_id' => 1, 'customer_id' => null,
                'payload_json' => '[{"name":"mail","value":"g@example.com"}]'],
            ['submission_id' => 3, 'form_id' => 1, 'customer_id' => null, 'payload_json' => '[]'],
        ];

        $emails = $resolver->resolve($rows, [1 => ['email_field' => 'mail']]);

        $this->assertSame([1 => 'a@example.com', 2 => 'g@example.com'], $emails);
        $this->assertSame(1, $this->customerQueries);
    }
}
