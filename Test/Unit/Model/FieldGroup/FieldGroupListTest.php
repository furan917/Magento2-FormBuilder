<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldGroup;

use Furan\FormBuilder\Test\Unit\Helper\ModelFixtures;
use Furan\FormBuilder\Model\FieldGroup;
use Furan\FormBuilder\Model\FieldGroup\FieldGroupList;
use Furan\FormBuilder\Model\ResourceModel\FieldGroup\Collection;
use Furan\FormBuilder\Model\ResourceModel\FieldGroup\CollectionFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class FieldGroupListTest extends TestCase
{
    /** @var list<array{string, array}> */
    private array $selectCalls = [];
    /** @var list<string[]> */
    private array $selected = [];

    /**
     * @param list<array{int, string}> $rows group id, name of the rows the page query returns
     * @param array<int, string> $names group id => name the cursor lookup finds
     */
    private function list(array $rows, array $names = []): FieldGroupList
    {
        $collections = $this->createStub(CollectionFactory::class);
        $collections->method('create')->willReturnCallback(fn() => $this->collection($rows, $names));
        return new FieldGroupList($collections);
    }

    private function collection(array $rows, array $names): Collection
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteInto')->willReturnCallback(static fn (string $sql, $value) => str_replace('?', "'" . $value . "'", $sql));
        $select = $this->createStub(Select::class);
        foreach (['where', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($method, $select) {
                $this->selectCalls[] = [$method, $args];
                return $select;
            });
        }
        $collection = $this->createStub(Collection::class);
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getConnection')->willReturn($connection);
        $collection->method('addFieldToSelect')->willReturnCallback(function ($fields) use ($collection) {
            $this->selected[] = (array)$fields;
            return $collection;
        });
        $collection->method('addFieldToFilter')->willReturnCallback(function (string $field, $condition) use ($collection, $names) {
            $this->selectCalls[] = ['filter', [$field, $condition]];
            $collection->method('getFirstItem')->willReturn($this->item((int)$condition, $names[$condition] ?? null));
            return $collection;
        });
        $items = array_map(fn (array $r) => $this->item($r[0], $r[1]), $rows);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));
        return $collection;
    }

    private function item(int $id, ?string $name): FieldGroup
    {
        return $name === null
            ? ModelFixtures::model(FieldGroup::class)
            : ModelFixtures::model(FieldGroup::class, $id, ['name' => $name, 'description' => 'd', 'updated_at' => '2026-11-08 10:00:00']);
    }

    public function testItPagesFieldGroupsByNameOnlyAndReturnsAnEmptyPageAfterADeletedCursor(): void
    {
        $page = $this->list([[3, 'Address']], [])->page(99, 20);

        $this->assertSame(['rows' => [], 'next' => null], $page);
        $this->assertSame([], array_filter($this->selectCalls, static fn (array $c) => $c[0] === 'limit'));
    }

    public function testItListsGroupsOrderedByNameWithKeysetPaging(): void
    {
        $page = $this->list([[3, 'Address'], [1, 'Billing'], [2, 'Contact']], [5 => 'Aardvark'])->page(5, 2);

        $this->assertSame(['Address', 'Billing'], array_column($page['rows'], 'name'));
        $this->assertSame(1, $page['next']);
        $this->assertSame(['group_id', 'name', 'description', 'updated_at'], $this->selected[0]);

        $methods = array_column($this->selectCalls, 0);
        $this->assertNotContains('limitPage', $methods);
        $this->assertContains(['limit', [3, null]], $this->selectCalls);
        $this->assertContains(['order', ['main_table.name ASC']], $this->selectCalls);
        $where = array_values(array_filter($this->selectCalls, static fn (array $c) => $c[0] === 'where'));
        $this->assertSame(
            "main_table.name > 'Aardvark'",
            $where[0][1][0]
        );
    }

    public function testItStartsAtTheFirstGroupWithoutACursor(): void
    {
        $page = $this->list([[3, 'Address']])->page(0, 20);

        $this->assertNull($page['next']);
        $this->assertSame([], array_filter($this->selectCalls, static fn (array $c) => $c[0] === 'where'));
    }
}
