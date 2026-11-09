<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Model\FieldType\ItemList;
use PHPUnit\Framework\TestCase;

class ItemListTest extends TestCase
{
    public function testItIsAMultiValueInputWithAClampedMaximum(): void
    {
        $type = new ItemList();
        $this->assertSame('list', $type->getCode());
        $this->assertTrue($type->isMultiValue());
        $this->assertSame(5, $type->getMaxItems([]));
        $this->assertSame(20, $type->getMaxItems(['settings' => ['max_items' => 500]]));
        $this->assertSame(2, $type->getMaxItems(['settings' => ['max_items' => 0]]));
        $this->assertSame('You can add up to 3 items.', $type->getInvalidMessage(['settings' => ['max_items' => 3]]));
    }

    public function testItCapsEachItemLength(): void
    {
        $value = (new ItemList())->normalizeValue([str_repeat('a', 300)], []);
        $this->assertSame(255, mb_strlen($value[0]));
    }

    public function testItRejectsANonArrayNonScalarValue(): void
    {
        $this->assertNull((new ItemList())->normalizeValue(new \stdClass(), []));
    }
}
