<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Prefill;

use Furan\FormBuilder\Model\Prefill\LockedValues;
use Furan\FormBuilder\Model\Prefill\PrefillService;
use Furan\FormBuilder\Test\Unit\Helper\ArrayPrefillSource;
use Furan\FormBuilder\Test\Unit\Helper\PrefillSourcePools;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class LockedValuesTest extends TestCase
{
    private PrefillService&Stub $prefill;
    private ArrayPrefillSource $stock;

    protected function setUp(): void
    {
        $this->prefill = $this->createStub(PrefillService::class);
        $this->stock = new ArrayPrefillSource('product.stock', 'product', ['text'], '42');
    }

    private function locked(): LockedValues
    {
        return new LockedValues($this->prefill, PrefillSourcePools::builtIn([
            'product.stock' => $this->stock,
            'customer.loyalty' => new ArrayPrefillSource('customer.loyalty', 'customer', ['text'], 'L-1'),
        ]));
    }

    private function field(string $name, string $type, string $prefill, bool $locked = true): array
    {
        return ['kind' => 'field', 'name' => $name, 'type' => $type, 'prefill' => $prefill, 'locked' => $locked];
    }

    private function product(): array
    {
        return ['id' => 9, 'sku' => 'SKU-9', 'name' => 'Widget', 'url' => 'https://shop.test/w.html', 'price' => 19.9,
            'priceFormatted' => '$19.90', 'currency' => 'USD'];
    }

    public function testItOverwritesLockedFieldsWithTheCustomersRealData(): void
    {
        $prefill = $this->prefill = $this->createMock(PrefillService::class);
        $fields = [$this->field('first', 'text', 'customer.firstname'), $this->field('last', 'text', 'customer.lastname')];
        $prefill->expects($this->once())->method('valuesFor')
            ->with(5, ['customer.firstname', 'customer.lastname'], 2)
            ->willReturn(['customer.firstname' => 'Ann', 'customer.lastname' => 'Real']);

        $result = $this->locked()->apply($fields, ['first' => 'Mallory', 'other' => 'x'], 5, null, 2);

        $this->assertSame(['first' => 'Ann', 'other' => 'x', 'last' => 'Real'], $result);
    }

    public function testItLeavesLockedCustomerFieldsAsPostedForGuests(): void
    {
        $prefill = $this->prefill = $this->createMock(PrefillService::class);
        $prefill->expects($this->never())->method('valuesFor');

        $result = $this->locked()->apply([$this->field('first', 'text', 'customer.firstname')], ['first' => 'Bob'], 0, null, 2);

        $this->assertSame(['first' => 'Bob'], $result);
    }

    public function testItOverwritesLockedProductFieldsFromTheReResolvedProductForGuestsToo(): void
    {
        $prefill = $this->prefill = $this->createMock(PrefillService::class);
        $fields = [
            $this->field('p_name', 'text', 'product.name'),
            $this->field('p_sku', 'hidden', 'product.sku'),
            $this->field('p_url', 'url', 'product.url'),
            $this->field('p_price', 'number', 'product.price'),
        ];
        $prefill->expects($this->never())->method('valuesFor');

        $result = $this->locked()->apply($fields, ['p_name' => 'Evil', 'p_price' => '0.01'], 0, $this->product(), 2);

        $this->assertSame(
            ['p_name' => 'Widget', 'p_price' => '19.9', 'p_sku' => 'SKU-9', 'p_url' => 'https://shop.test/w.html'],
            $result
        );
    }

    public function testItReReadsLockedProductSourceValuesFromTheCatalogueOnSubmit(): void
    {
        $prefill = $this->prefill = $this->createMock(PrefillService::class);
        $prefill->expects($this->never())->method('valuesFor');

        $result = $this->locked()->apply([$this->field('stock', 'text', 'product.stock')], ['stock' => '9999'], 0, $this->product(), 2);

        $this->assertSame(['stock' => '42'], $result);
        $this->assertSame($this->product(), $this->stock->contexts[0]->getProduct());
    }

    public function testItIgnoresThePostedValueOfALockedFieldWhoseSourceIsNoLongerRegistered(): void
    {
        $prefill = $this->prefill = $this->createMock(PrefillService::class);
        $prefill->expects($this->never())->method('valuesFor');

        $result = $this->locked()->apply([$this->field('gone', 'text', 'loyalty.removed')], ['gone' => 'Evil'], 5, null, 2);

        $this->assertSame(['gone' => ''], $result);
    }

    public function testItReturnsNoPrefillValueWhenTheSourceDoesNotSuitTheFieldType(): void
    {
        $prefill = $this->prefill = $this->createMock(PrefillService::class);
        $prefill->expects($this->never())->method('valuesFor');

        $result = $this->locked()->apply([$this->field('pick', 'select', 'customer.firstname')], ['pick' => 'Evil'], 5, null, 2);

        $this->assertSame(['pick' => ''], $result);
    }

    public function testItBlanksLockedProductFieldsWhenTheProductIdDoesNotResolve(): void
    {
        $result = $this->locked()->apply([$this->field('p_name', 'text', 'product.name')], ['p_name' => 'Evil'], 5, null, 2);

        $this->assertSame(['p_name' => ''], $result);
    }

    public function testItBlanksALockedFieldWhenTheCustomerHasNoValueForIt(): void
    {
        $fields = [$this->field('phone', 'tel', 'address.billing.telephone'), $this->field('addr', 'address', 'address.billing')];
        $this->prefill->method('valuesFor')->willReturn(['address.billing.telephone' => '', 'address.billing' => '']);

        $result = $this->locked()->apply($fields, ['phone' => '123', 'addr' => ['city' => 'X']], 5, null, 2);

        $this->assertSame(['phone' => '', 'addr' => ''], $result);
    }

    public function testItNamesTheLockedFieldsWhoseAnswerTheServerOwns(): void
    {
        $fields = [
            $this->field('first', 'text', 'customer.firstname'),
            $this->field('p_name', 'text', 'product.name'),
            $this->field('gone', 'text', 'loyalty.removed'),
            $this->field('free', 'text', 'customer.lastname', false),
            ['kind' => 'field', 'name' => 'plain', 'type' => 'text'],
        ];

        $this->assertSame(['first', 'p_name', 'gone'], $this->locked()->serverOwned($fields, 5));
        $this->assertSame(['p_name', 'gone'], $this->locked()->serverOwned($fields, 0));
    }

    public function testItIgnoresUnlockedFieldsAndSkipsTheLookupWhenNothingIsLocked(): void
    {
        $prefill = $this->prefill = $this->createMock(PrefillService::class);
        $prefill->expects($this->never())->method('valuesFor');

        $result = $this->locked()->apply([$this->field('first', 'text', 'customer.firstname', false)], ['first' => 'Bob'], 5, null, 2);

        $this->assertSame(['first' => 'Bob'], $result);
    }

    public function testItKeepsTheStoredAddressShapeAndCastsScalarsToStrings(): void
    {
        $address = ['street' => ['1 Main St'], 'city' => 'Town', 'region_id' => '12', 'region' => 'R',
            'postcode' => 'AB1', 'country_id' => 'GB'];
        $fields = [$this->field('addr', 'address', 'address.billing'), $this->field('n', 'text', 'customer.taxvat')];
        $this->prefill->method('valuesFor')->willReturn(['address.billing' => $address, 'customer.taxvat' => 123]);

        $result = $this->locked()->apply($fields, [], 5, null, 2);

        $this->assertSame($address, $result['addr']);
        $this->assertSame('123', $result['n']);
    }

    public function testItResolvesTheProductOnlyForAPositiveId(): void
    {
        $prefill = $this->prefill = $this->createMock(PrefillService::class);
        $prefill->expects($this->once())->method('product')->with(9, 2)->willReturn($this->product());

        $this->assertNull($this->locked()->product(0, 2));
        $this->assertSame($this->product(), $this->locked()->product(9, 2));
    }

    public function testItBuildsTheProductSnapshotWithoutFormattedPrice(): void
    {
        $this->assertSame(
            ['id' => 9, 'sku' => 'SKU-9', 'name' => 'Widget', 'url' => 'https://shop.test/w.html', 'price' => 19.9,
                'currency' => 'USD'],
            $this->locked()->snapshot($this->product())
        );
        $this->assertNull($this->locked()->snapshot(null));
    }
}
