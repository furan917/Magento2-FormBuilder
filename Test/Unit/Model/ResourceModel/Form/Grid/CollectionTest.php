<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\ResourceModel\Form\Grid;

use Furan\FormBuilder\Model\ResourceModel\Form\Grid\Collection;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class CollectionTest extends TestCase
{
    public function testItHidesFormsThatAreBeingDeletedInTheBackground(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->expects($this->once())->method('where')->with('main_table.purge_requested_at IS NULL')->willReturnSelf();
        $collection = new class ($select) extends Collection {
            public function __construct(private readonly Select $select)
            {
            }

            public function getSelect()
            {
                return $this->select;
            }

            public function getMainTable()
            {
                return 'furan_formbuilder_form';
            }
        };

        (new \ReflectionMethod($collection, '_initSelect'))->invoke($collection);
    }
}
