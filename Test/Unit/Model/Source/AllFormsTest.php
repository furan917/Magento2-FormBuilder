<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Source;

use Furan\FormBuilder\Model\ResourceModel\Form\Collection;
use Furan\FormBuilder\Model\ResourceModel\Form\CollectionFactory;
use Furan\FormBuilder\Model\Source\AllForms;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;

class AllFormsTest extends TestCase
{
    public function testItListsAllFormsIncludingInactiveOnesAsFilterOptions(): void
    {
        $active = new DataObject(['form_id' => '1', 'title' => 'Contact', 'is_active' => 1]);
        $inactive = new DataObject(['form_id' => '2', 'title' => '<b>Old</b>', 'is_active' => 0]);
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())->method('addFieldToSelect')
            ->with(['form_id', 'title'])->willReturnSelf();
        $collection->expects($this->never())->method('addFieldToFilter');
        $collection->expects($this->never())->method('addStoreFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$active, $inactive]));
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $this->assertSame(
            [['value' => 1, 'label' => 'Contact'], ['value' => 2, 'label' => '<b>Old</b>']],
            (new AllForms($factory))->toOptionArray()
        );
    }
}
