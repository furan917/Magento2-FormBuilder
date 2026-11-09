<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Prefill;

use Furan\FormBuilder\Model\Prefill\PreviousAnswers;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class PreviousAnswersTest extends TestCase
{
    private const SCHEMA = ['pages' => [['items' => [
        ['kind' => 'field', 'name' => 'a', 'type' => 'text'],
        ['kind' => 'section', 'items' => [
            ['kind' => 'field', 'name' => 'doc', 'type' => 'file'],
            ['kind' => 'field', 'name' => 'ref', 'type' => 'hidden'],
        ]],
    ]]]];

    private function service(?array $row): PreviousAnswers
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchRow')->willReturn($row ?? false);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new PreviousAnswers($resource, new Json(), FieldPools::withFiles());
    }

    private function row(array $payload, int $status = 0, int $customer = 5, int $form = 12): array
    {
        return [
            'form_id' => (string)$form, 'customer_id' => (string)$customer, 'status' => (string)$status,
            'payload_json' => json_encode($payload), 'saved' => '1800000000',
        ];
    }

    public function testItNeverReturnsFileValuesInThePreviousAnswers(): void
    {
        $row = $this->row([
            ['name' => 'a', 'type' => 'text', 'value' => 'x'],
            ['name' => 'doc', 'type' => 'file', 'value' => ['file' => 'p/q.pdf', 'name' => 'q.pdf', 'size' => 3]],
        ]);

        $this->assertSame(['a' => 'x'], $this->service($row)->load(4, 12, 5, self::SCHEMA)['answers'] ?? null);
    }

    public function testItNeverReturnsHiddenFieldValuesInThePreviousAnswers(): void
    {
        $row = $this->row([
            ['name' => 'a', 'type' => 'text', 'value' => 'x'],
            ['name' => 'ref', 'type' => 'hidden', 'value' => 'secret'],
            ['name' => 'gone', 'type' => 'text', 'value' => 'removed from schema'],
        ]);

        $this->assertSame(['a' => 'x'], $this->service($row)->load(4, 12, 5, self::SCHEMA)['answers'] ?? null);
    }

    public function testItReturnsNullWhenTheSubmissionCanNoLongerBeEdited(): void
    {
        $payload = [['name' => 'a', 'type' => 'text', 'value' => 'x']];

        $this->assertNull($this->service($this->row($payload, 2))->load(4, 12, 5, self::SCHEMA));
        $this->assertNull($this->service($this->row($payload, 3))->load(4, 12, 5, self::SCHEMA));
        $this->assertNull($this->service($this->row($payload, 0, 6))->load(4, 12, 5, self::SCHEMA));
        $this->assertNull($this->service($this->row($payload, 0, 5, 99))->load(4, 12, 5, self::SCHEMA));
        $this->assertNull($this->service(null)->load(4, 12, 5, self::SCHEMA));
    }

    public function testItNeverReturnsCustomFileTypeValuesInThePreviousAnswers(): void
    {
        $schema = ['pages' => [['items' => [
            ['kind' => 'field', 'name' => 'a', 'type' => 'text'],
            ['kind' => 'field', 'name' => 'doc', 'type' => 'doc'],
        ]]]];
        $row = $this->row([
            ['name' => 'a', 'type' => 'text', 'value' => 'x'],
            ['name' => 'doc', 'type' => 'doc', 'value' => ['file' => 'p/q.pdf', 'name' => 'q.pdf', 'size' => 3]],
        ]);

        $this->assertSame(['a' => 'x'], $this->service($row)->load(4, 12, 5, $schema)['answers'] ?? null);
    }

    public function testItReturnsWhenTheAnswerWasLastSavedInMilliseconds(): void
    {
        $row = $this->row([['name' => 'a', 'type' => 'text', 'value' => 'x']]);

        $this->assertSame(1800000000000, $this->service($row)->load(4, 12, 5, self::SCHEMA)['savedAt'] ?? null);
    }
}
