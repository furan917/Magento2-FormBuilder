<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Directory;

use Furan\FormBuilder\Model\Directory\CountryOptions;
use Magento\Directory\Model\ResourceModel\Country\Collection;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory;
use PHPUnit\Framework\TestCase;

class CountryOptionsTest extends TestCase
{
    public function testItReturnsSortedCountryOptionsForTheStore(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())->method('loadByStore')->with(1)->willReturnSelf();
        $collection->method('toOptionArray')->with(false)->willReturn([
            ['value' => 'GB', 'label' => 'United Kingdom'],
            ['value' => 'FR', 'label' => 'France'],
            ['value' => 'DE', 'label' => 'germany'],
        ]);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $this->assertSame(
            [
                ['value' => 'FR', 'label' => 'France'],
                ['value' => 'DE', 'label' => 'germany'],
                ['value' => 'GB', 'label' => 'United Kingdom'],
            ],
            (new CountryOptions($factory))->forStore(1)
        );
    }
}
