<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Prefill;

use Furan\FormBuilder\Model\Prefill\LockedValues;
use Furan\FormBuilder\Model\Prefill\PrefillService;
use Furan\FormBuilder\Test\Unit\Helper\ArrayPrefillSource;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Test\Unit\Helper\PrefillSourcePools;
use Furan\FormBuilder\Test\Unit\Helper\StampType;
use Furan\FormBuilder\Model\FieldType\Pool;
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
        ]), FieldPools::builtIn());
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

    private function hidden(string $name, array $settings, string $default, string $prefill = ''): array
    {
        return ['kind' => 'field', 'name' => $name, 'type' => 'hidden', 'default' => $default, 'settings' => $settings,
            'prefill' => $prefill, 'locked' => false];
    }

    public function testItOverwritesAFixedValueHiddenFieldWithItsDefault(): void
    {
        $fields = [$this->hidden('campaign', ['source' => 'static'], 'spring-sale')];

        $result = $this->locked()->apply($fields, ['campaign' => 'tampered', 'other' => 'x'], 0, null, 1);

        $this->assertSame(['campaign' => 'spring-sale', 'other' => 'x'], $result);
    }

    public function testItSetsAFixedValueHiddenFieldThatWasNotPosted(): void
    {
        $result = $this->locked()->apply([$this->hidden('campaign', ['source' => 'static'], 'spring-sale')], [], 0, null, 1);

        $this->assertSame(['campaign' => 'spring-sale'], $result);
    }

    public function testItTreatsAHiddenFieldWithoutASourceAsAFixedValue(): void
    {
        $result = $this->locked()->apply([$this->hidden('campaign', [], 'spring-sale')], ['campaign' => 'tampered'], 0, null, 1);

        $this->assertSame(['campaign' => 'spring-sale'], $result);
    }

    public function testItKeepsThePostedValueOfBrowserSourcedHiddenFields(): void
    {
        $fields = [
            $this->hidden('q', ['source' => 'query', 'query_param' => 'utm_source'], 'direct'),
            $this->hidden('url', ['source' => 'page_url'], ''),
            $this->hidden('ref', ['source' => 'referrer'], ''),
            $this->hidden('crm', ['source' => 'crm.id'], ''),
        ];
        $posted = ['q' => 'google', 'url' => 'https://shop.test/forms/x', 'ref' => 'https://example.com/', 'crm' => 'C-7'];

        $this->assertSame($posted, $this->locked()->apply($fields, $posted, 0, null, 1));
    }

    public function testItStoresTheDefaultOfAPrefilledHiddenFieldForGuestsWhateverWasPosted(): void
    {
        $prefill = $this->prefill = $this->createMock(PrefillService::class);
        $prefill->expects($this->never())->method('valuesFor');
        $fields = [
            $this->hidden('mail', ['source' => 'static'], 'guest-default', 'customer.email'),
            ['locked' => true] + $this->hidden('first', ['source' => 'static'], '', 'customer.firstname'),
            $this->hidden('q', ['source' => 'query', 'query_param' => 'q'], 'none', 'customer.lastname'),
        ];

        $result = $this->locked()->apply($fields, ['mail' => 'victim@example.com', 'first' => 'Evil', 'q' => 'x'], 0, null, 1);

        $this->assertSame(['mail' => 'guest-default', 'first' => '', 'q' => 'none'], $result);
    }

    public function testItStoresTheAccountValueOfAPrefilledHiddenFieldForCustomersLockedOrNot(): void
    {
        $prefill = $this->prefill = $this->createMock(PrefillService::class);
        $prefill->expects($this->once())->method('valuesFor')
            ->with(5, ['customer.email', 'customer.firstname'], 2)
            ->willReturn(['customer.email' => 'ann@example.com', 'customer.firstname' => '']);
        $fields = [
            $this->hidden('mail', ['source' => 'query', 'query_param' => 'm'], 'd', 'customer.email'),
            ['locked' => true] + $this->hidden('first', [], 'd', 'customer.firstname'),
        ];

        $result = $this->locked()->apply($fields, ['mail' => 'mallory@example.com', 'first' => 'Evil'], 5, null, 2);

        $this->assertSame(['mail' => 'ann@example.com', 'first' => ''], $result);
    }

    public function testItStoresTheProductValueOfAnUnlockedPrefilledHiddenField(): void
    {
        $fields = [$this->hidden('sku', [], 'd', 'product.sku')];

        $this->assertSame(['sku' => 'SKU-9'], $this->locked()->apply($fields, ['sku' => 'Evil'], 0, $this->product(), 2));
        $this->assertSame(['sku' => ''], $this->locked()->apply($fields, ['sku' => 'Evil'], 0, null, 2));
    }

    public function testItBlanksAPrefilledHiddenFieldWhoseSourceDoesNotSuitIt(): void
    {
        $fields = [$this->hidden('loyal', [], 'd', 'customer.loyalty'), $this->hidden('gone', [], 'd', 'loyalty.removed')];

        $this->assertSame(['loyal' => '', 'gone' => ''], $this->locked()->apply($fields, ['loyal' => 'L', 'gone' => 'G'], 5, null, 2));
    }

    public function testItBlanksAGuestsPrefilledAnswerToAServerValueTypeWithoutAFixedValue(): void
    {
        $pool = FieldPools::builtIn()->all() + [StampType::CODE => new StampType()];
        $values = new LockedValues($this->prefill, PrefillSourcePools::builtIn([
            'customer.stamp' => new ArrayPrefillSource('customer.stamp', 'customer', [StampType::CODE], 'S-1'),
        ]), new Pool($pool));
        $field = ['kind' => 'field', 'name' => 's', 'type' => StampType::CODE, 'prefill' => 'customer.stamp', 'locked' => false];

        $this->assertSame(['s' => ''], $values->apply([$field], ['s' => 'Evil'], 0, null, 2));
    }

    public function testItNamesPrefilledHiddenFieldsAsServerOwnedForGuestsAndCustomers(): void
    {
        $fields = [
            $this->hidden('mail', [], '', 'customer.email'),
            $this->hidden('sku', [], '', 'product.sku'),
            $this->hidden('plain', [], 'x'),
            $this->field('typed', 'text', 'customer.email', false),
        ];

        $this->assertSame(['mail', 'sku'], $this->locked()->serverOwned($fields, 0));
        $this->assertSame(['mail', 'sku'], $this->locked()->serverOwned($fields, 5));
    }

    public function testItIgnoresMalformedHiddenSettingsWithoutWarnings(): void
    {
        $fields = [
            ['kind' => 'field', 'name' => 'a', 'type' => 'hidden', 'default' => ['x'], 'settings' => []],
            ['kind' => 'field', 'name' => 'b', 'type' => 'hidden', 'default' => 'd', 'settings' => ['source' => ['static']]],
            ['kind' => 'field', 'name' => 'c', 'type' => 'hidden', 'default' => 'd', 'settings' => 'static'],
        ];

        $this->assertSame(['b' => 'p', 'a' => '', 'c' => 'd'], $this->locked()->apply($fields, ['b' => 'p'], 0, null, 1));
    }

    public function testItNeverFixesTheValueOfOtherFieldTypes(): void
    {
        $field = ['kind' => 'field', 'name' => 'note', 'type' => 'text', 'default' => 'd', 'settings' => ['source' => 'static']];

        $this->assertSame(['note' => 'typed'], $this->locked()->apply([$field], ['note' => 'typed'], 0, null, 1));
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
