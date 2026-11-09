<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\ResourceModel\Form;

use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Furan\FormBuilder\Model\ResourceModel\Form\Collection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CollectionTest extends TestCase
{
    public function testItFiltersByTheStoreOrAllStoreViews(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('distinct')->willReturnSelf();
        $select->expects($this->once())->method('join')
            ->with(['store_link' => 'furan_formbuilder_form_store'], 'store_link.form_id = main_table.form_id', [])
            ->willReturnSelf();
        $select->expects($this->once())->method('where')->with('store_link.store_id IN (?)', [0, 2])->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $resource = $this->createStub(FormResource::class);
        $resource->method('getTable')->willReturnArgument(0);
        $resource->method('getConnection')->willReturn($connection);

        $collection = new Collection(
            $this->createStub(EntityFactoryInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(FetchStrategyInterface::class),
            $this->createStub(ManagerInterface::class),
            $connection,
            $resource
        );

        $this->assertSame($collection, $collection->addStoreFilter(2));
    }

    public function testItLoadsStoreIdsForEveryCollectionItemInOneQuery(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->expects($this->once())->method('where')->with('form_id IN (?)', [4, 7])->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->expects($this->once())->method('fetchAll')->with($select)->willReturn([
            ['form_id' => '4', 'store_id' => '0'],
            ['form_id' => '7', 'store_id' => '1'],
            ['form_id' => '7', 'store_id' => '2'],
        ]);
        $resource = $this->createStub(FormResource::class);
        $resource->method('getTable')->willReturnArgument(0);
        $resource->method('getConnection')->willReturn($connection);

        $collection = new Collection(
            $this->createStub(EntityFactoryInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(FetchStrategyInterface::class),
            $this->createStub(ManagerInterface::class),
            $connection,
            $resource
        );
        $first = new DataObject(['form_id' => '4']);
        $second = new DataObject(['form_id' => '7']);
        $collection->addItem($first);
        $collection->addItem($second);

        (new \ReflectionMethod($collection, '_afterLoad'))->invoke($collection);

        $this->assertSame([0], $first->getData('store_ids'));
        $this->assertSame([1, 2], $second->getData('store_ids'));
    }

    public function testItMigratesTheSchemaOfItemsThatSelectedIt(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([]);
        $resource = $this->createMock(FormResource::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->expects($this->once())->method('upgradeSchemaJson')->with('{"version":1}')->willReturn('{"version":2}');
        $collection = new Collection(
            $this->createStub(EntityFactoryInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(FetchStrategyInterface::class),
            $this->createStub(ManagerInterface::class),
            $connection,
            $resource
        );
        $with = new DataObject(['schema_json' => '{"version":1}']);
        $without = new DataObject(['title' => 'x']);
        $collection->addItem($with);
        $collection->addItem($without);

        (new \ReflectionMethod($collection, '_afterLoad'))->invoke($collection);

        $this->assertSame('{"version":2}', $with->getData('schema_json'));
        $this->assertFalse($without->hasData('schema_json'));
    }

    public function testItSkipsTheStoreLookupAndMigrationWhenOnlySomeColumnsAreSelected(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->expects($this->never())->method('fetchAll');
        $resource = $this->createMock(FormResource::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getIdFieldName')->willReturn('form_id');
        $resource->expects($this->never())->method('upgradeSchemaJson');
        $collection = new Collection(
            $this->createStub(EntityFactoryInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(FetchStrategyInterface::class),
            $this->createStub(ManagerInterface::class),
            $connection,
            $resource
        );
        $collection->addFieldToSelect('form_id');
        $item = new DataObject(['form_id' => '4']);
        $collection->addItem($item);

        (new \ReflectionMethod($collection, '_afterLoad'))->invoke($collection);

        $this->assertFalse($item->hasData('store_ids'));
    }
}
