<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldGroup;

use Furan\FormBuilder\Model\FieldGroup\GroupUsage;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GroupUsageTest extends TestCase
{
    private Mysql&MockObject $connection;
    /** @var list<array{string, array<mixed>}> */
    private array $calls = [];

    private function usage(): GroupUsage
    {
        $this->connection = $this->createMock(Mysql::class);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $select = $this->createStub(Select::class);
        foreach (['from', 'join', 'where', 'order', 'limit', 'group'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($method, $select) {
                while ($args !== [] && end($args) === null) {
                    array_pop($args);
                }
                $this->calls[] = [$method, $args];
                return $select;
            });
        }
        $this->connection->method('select')->willReturn($select);
        return new GroupUsage($resource);
    }

    /** @return list<array<mixed>> */
    private function called(string $method): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call[1],
            array_filter($this->calls, static fn (array $call): bool => $call[0] === $method)
        ));
    }

    public function testItCollectsTheGroupIdsOfItemsAndSectionChildrenOnce(): void
    {
        $schema = ['pages' => [
            ['items' => [
                ['kind' => 'field', 'source_group' => 4],
                ['kind' => 'section', 'source_group' => 7, 'items' => [['kind' => 'field', 'source_group' => 9]]],
                ['kind' => 'field'],
            ]],
            ['items' => [['kind' => 'content', 'source_group' => 4], ['kind' => 'field', 'source_group' => '2']]],
        ]];

        $this->assertSame([4, 7, 9], GroupUsage::groupIds($schema));
    }

    public function testItIgnoresMalformedSchemasAndIds(): void
    {
        $this->assertSame([], GroupUsage::groupIds([]));
        $this->assertSame([], GroupUsage::groupIds(['pages' => 'x']));
        $this->assertSame([], GroupUsage::groupIds(['pages' => [['items' => [['source_group' => 0], ['source_group' => -3], 'x']]]]));
    }

    public function testSyncReplacesTheFormsLinksWithTheGroupsThatStillExist(): void
    {
        $usage = $this->usage();
        $this->connection->expects($this->once())->method('delete')
            ->with(GroupUsage::TABLE, ['form_id = ?' => 12]);
        $this->connection->expects($this->once())->method('insertFromSelect')
            ->with($this->isInstanceOf(Select::class), GroupUsage::TABLE, ['group_id', 'form_id'], AdapterInterface::INSERT_IGNORE)
            ->willReturn('INSERT');
        $this->connection->expects($this->once())->method('query')->with('INSERT');

        $usage->sync(12, ['pages' => [['items' => [['kind' => 'field', 'source_group' => 3]]]]]);

        $this->assertContains(['group_id IN (?)', [3]], $this->called('where'));
    }

    public function testSyncOnlyClearsTheLinksWhenTheFormHasNoGroupFields(): void
    {
        $usage = $this->usage();
        $this->connection->expects($this->once())->method('delete');
        $this->connection->expects($this->never())->method('insertFromSelect');

        $usage->sync(12, ['pages' => [['items' => [['kind' => 'field']]]]]);
    }

    public function testItListsTheFirstFormsUsingAGroupWithTheTotal(): void
    {
        $usage = $this->usage();
        $this->connection->expects($this->once())->method('fetchAll')->willReturn([
            ['form_id' => '3', 'title' => 'Contact'],
            ['form_id' => '8', 'title' => 'Returns'],
        ]);
        $this->connection->method('fetchOne')->willReturn('5');

        $result = $usage->formsUsing(4, 2);

        $this->assertSame([['id' => 3, 'title' => 'Contact'], ['id' => 8, 'title' => 'Returns']], $result['rows']);
        $this->assertSame(5, $result['total']);
        $this->assertContains([2], $this->called('limit'));
        $this->assertContains(['link.group_id = ?', 4], $this->called('where'));
    }

    public function testItSkipsTheCountWhenTheListIsShorterThanTheLimit(): void
    {
        $usage = $this->usage();
        $this->connection->method('fetchAll')->willReturn([['form_id' => '3', 'title' => 'Contact']]);
        $this->connection->expects($this->never())->method('fetchOne');

        $this->assertSame(1, $usage->formsUsing(4, 20)['total']);
    }

    public function testItCountsFormsPerGroupForAPageOfGroups(): void
    {
        $usage = $this->usage();
        $this->connection->expects($this->once())->method('fetchPairs')->willReturn(['4' => '2', '9' => '1']);

        $this->assertSame([4 => 2, 9 => 1], $usage->counts([4, 9, 11]));
        $this->assertContains(['group_id IN (?)', [4, 9, 11]], $this->called('where'));
        $this->assertSame([], $usage->counts([]));
    }
}
