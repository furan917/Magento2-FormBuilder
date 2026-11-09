<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Source;

use Furan\FormBuilder\Model\ResourceModel\Form\Collection;
use Furan\FormBuilder\Model\ResourceModel\Form\CollectionFactory;
use Furan\FormBuilder\Model\Source\Form;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;

class FormTest extends TestCase
{
    public function testItListsActiveFormsAsWidgetOptionsSortedByTitle(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())->method('addFieldToSelect')
            ->with(['form_id', 'title', 'identifier'])->willReturnSelf();
        $collection->expects($this->once())->method('addFieldToFilter')->with('is_active', 1)->willReturnSelf();
        $collection->expects($this->once())->method('setOrder')->with('title', 'ASC')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            new DataObject(['form_id' => '3', 'title' => 'Apply', 'identifier' => 'apply']),
            new DataObject(['form_id' => '1', 'title' => 'Contact', 'identifier' => 'contact-us']),
        ]));
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $this->assertSame(
            [
                ['value' => 3, 'label' => 'Apply (apply)'],
                ['value' => 1, 'label' => 'Contact (contact-us)'],
            ],
            (new Form($factory))->toOptionArray()
        );
    }
}
