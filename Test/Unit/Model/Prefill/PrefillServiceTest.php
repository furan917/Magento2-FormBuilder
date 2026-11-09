<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Prefill;

use Furan\FormBuilder\Model\Prefill\PrefillService;
use Furan\FormBuilder\Test\Unit\Helper\ArrayPrefillSource;
use Furan\FormBuilder\Test\Unit\Helper\PrefillSourcePools;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\AttributeMetadataInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\OptionInterface;
use Magento\Customer\Api\Data\RegionInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class PrefillServiceTest extends TestCase
{
    private CustomerRepositoryInterface&Stub $customers;
    private ProductRepositoryInterface&Stub $products;
    private PriceCurrencyInterface&Stub $priceCurrency;
    private Store&Stub $store;
    private CustomerMetadataInterface&Stub $metadata;
    private PrefillService $service;
    private ArrayPrefillSource $custom;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?CustomerRepositoryInterface $customers = null, ?CustomerMetadataInterface $metadata = null, ?PriceCurrencyInterface $priceCurrency = null, ?ProductRepositoryInterface $products = null): void
    {
        $this->customers = $customers ?? $this->createStub(CustomerRepositoryInterface::class);
        $this->products = $products ?? $this->createStub(ProductRepositoryInterface::class);
        $this->priceCurrency = $priceCurrency ?? $this->createStub(PriceCurrencyInterface::class);
        $this->metadata = $metadata ?? $this->createStub(CustomerMetadataInterface::class);
        $this->store = $this->createStub(Store::class);
        $this->store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($this->store);
        $this->custom = new ArrayPrefillSource('customer.loyalty', 'customer', [], 'L-1');
        $this->service = new PrefillService(
            $this->customers,
            $this->products,
            $storeManager,
            $this->priceCurrency,
            PrefillSourcePools::builtIn(['customer.loyalty' => $this->custom], $this->metadata)
        );
    }

    private function address(array $d): AddressInterface&Stub
    {
        $region = $this->createStub(RegionInterface::class);
        $region->method('getRegion')->willReturn($d['region'] ?? '');
        $region->method('getRegionId')->willReturn($d['region_id'] ?? null);
        $a = $this->createStub(AddressInterface::class);
        $a->method('getStreet')->willReturn($d['street'] ?? ['1 Main St']);
        $a->method('getCity')->willReturn($d['city'] ?? 'Town');
        $a->method('getRegion')->willReturn($region);
        $a->method('getRegionId')->willReturn($d['region_id'] ?? null);
        $a->method('getPostcode')->willReturn($d['postcode'] ?? '12345');
        $a->method('getCountryId')->willReturn($d['country_id'] ?? 'US');
        $a->method('getCompany')->willReturn($d['company'] ?? null);
        $a->method('getTelephone')->willReturn($d['telephone'] ?? null);
        return $a;
    }

    private function customer(array $addresses = [], string $billing = '', string $shipping = ''): CustomerInterface&Stub
    {
        $c = $this->createStub(CustomerInterface::class);
        $c->method('getFirstname')->willReturn('Ada');
        $c->method('getLastname')->willReturn('Lovelace');
        $c->method('getEmail')->willReturn('ada@example.com');
        $c->method('getDob')->willReturn('1815-12-10');
        $c->method('getGender')->willReturn(2);
        $c->method('getAddresses')->willReturn($addresses);
        $c->method('getDefaultBilling')->willReturn($billing);
        $c->method('getDefaultShipping')->willReturn($shipping);
        return $c;
    }

    public function testItResolvesPrefillValuesThroughTheSourcePool(): void
    {
        $customers = $this->createMock(CustomerRepositoryInterface::class);
        $this->fixture(customers: $customers);
        $customers->method('getById')->with(5)->willReturn($this->customer());

        $values = $this->service->valuesFor(5, ['customer.loyalty', 'customer.email'], 1);

        $this->assertSame(['customer.loyalty' => 'L-1', 'customer.email' => 'ada@example.com'], $values);
    }

    public function testItLoadsTheCustomerOnceForAnyNumberOfCustomerSources(): void
    {
        $customers = $this->createMock(CustomerRepositoryInterface::class);
        $this->fixture(customers: $customers);
        $customers->expects($this->once())->method('getById')->with(5)->willReturn($this->customer());

        $this->service->valuesFor(5, ['customer.loyalty', 'customer.email', 'customer.firstname', 'address.billing'], 1);

        $this->assertCount(1, $this->custom->contexts);
        $this->assertSame($this->custom->contexts[0]->getCustomer(), $this->custom->contexts[0]->getCustomer());
    }

    public function testItReturnsNothingForGuests(): void
    {
        $customers = $this->createMock(CustomerRepositoryInterface::class);
        $this->fixture(customers: $customers);
        $customers->expects($this->never())->method('getById');
        $this->assertSame([], $this->service->valuesFor(0, ['customer.email'], 1));
    }

    public function testItReturnsTheCustomersNameAndEmailForALoggedInCustomer(): void
    {
        $customers = $this->createMock(CustomerRepositoryInterface::class);
        $metadata = $this->createMock(CustomerMetadataInterface::class);
        $this->fixture(customers: $customers, metadata: $metadata);
        $customers->method('getById')->with(5)->willReturn($this->customer());
        $opt = $this->createStub(OptionInterface::class);
        $opt->method('getValue')->willReturn('2');
        $opt->method('getLabel')->willReturn('Female');
        $meta = $this->createStub(AttributeMetadataInterface::class);
        $meta->method('getOptions')->willReturn([$opt]);
        $metadata->method('getAttributeMetadata')->with('gender')->willReturn($meta);

        $values = $this->service->valuesFor(5, [
            'customer.firstname', 'customer.lastname', 'customer.email',
            'customer.dob', 'customer.gender', 'customer.taxvat', 'bogus.source',
        ], 1);

        $this->assertSame([
            'customer.firstname' => 'Ada',
            'customer.lastname' => 'Lovelace',
            'customer.email' => 'ada@example.com',
            'customer.dob' => '1815-12-10',
            'customer.gender' => 'Female',
            'customer.taxvat' => '',
        ], $values);
    }

    public function testItReturnsSavedAddressesInTheAddressFieldShape(): void
    {
        $bill = $this->address(['region' => 'Texas', 'region_id' => 57, 'telephone' => '555', 'company' => 'Acme']);
        $bill->method('getId')->willReturn('10');
        $ship = $this->address(['street' => ['2 Side St', 'Flat 3'], 'city' => 'Leeds', 'region' => 'Yorks',
            'postcode' => 'LS1', 'country_id' => 'GB']);
        $ship->method('getId')->willReturn('11');
        $this->customers->method('getById')->willReturn($this->customer([$bill, $ship], '10', '11'));

        $values = $this->service->valuesFor(5, [
            'address.billing', 'address.shipping',
            'address.billing.company', 'address.billing.telephone',
        ], 1);

        $this->assertSame([
            'street' => ['1 Main St'], 'city' => 'Town', 'region_id' => '57', 'region' => 'Texas',
            'postcode' => '12345', 'country_id' => 'US',
        ], $values['address.billing']);
        $this->assertSame([
            'street' => ['2 Side St', 'Flat 3'], 'city' => 'Leeds', 'region_id' => '', 'region' => 'Yorks',
            'postcode' => 'LS1', 'country_id' => 'GB',
        ], $values['address.shipping']);
        $this->assertSame('Acme', $values['address.billing.company']);
        $this->assertSame('555', $values['address.billing.telephone']);
    }

    private function product(int $status = Status::STATUS_ENABLED, int $visibility = Visibility::VISIBILITY_BOTH, array $websites = [1]): Product&Stub
    {
        $amount = $this->createStub(\Magento\Framework\Pricing\Amount\AmountInterface::class);
        $amount->method('getValue')->willReturn(19.5);
        $price = $this->createStub(\Magento\Framework\Pricing\Price\PriceInterface::class);
        $price->method('getAmount')->willReturn($amount);
        $info = $this->createMock(\Magento\Framework\Pricing\PriceInfoInterface::class);
        $info->method('getPrice')->with('final_price')->willReturn($price);
        $p = $this->createStub(Product::class);
        $p->method('getId')->willReturn(7);
        $p->method('getSku')->willReturn('SKU-7');
        $p->method('getName')->willReturn('Widget');
        $p->method('getProductUrl')->willReturn('https://shop.test/widget.html');
        $p->method('getStatus')->willReturn($status);
        $p->method('getVisibility')->willReturn($visibility);
        $p->method('getWebsiteIds')->willReturn($websites);
        $p->method('getPriceInfo')->willReturn($info);
        return $p;
    }

    public function testItResolvesProductNameSkuUrlAndFinalPriceFromAProductId(): void
    {
        $products = $this->createMock(ProductRepositoryInterface::class);
        $this->fixture(products: $products);
        $products->method('getById')->with(7, false, 1)->willReturn($this->product());
        $this->store->method('getCurrentCurrencyCode')->willReturn('EUR');
        $this->priceCurrency->method('format')->willReturn('€19.50');

        $this->assertSame([
            'id' => 7, 'sku' => 'SKU-7', 'name' => 'Widget', 'url' => 'https://shop.test/widget.html',
            'price' => 19.5, 'priceFormatted' => '€19.50', 'currency' => 'EUR',
        ], $this->service->product(7, 1));
    }

    public function testItIgnoresAProductIdThatIsNotVisibleInTheStore(): void
    {
        $this->products->method('getById')->willReturnOnConsecutiveCalls(
            $this->product(Status::STATUS_DISABLED),
            $this->product(Status::STATUS_ENABLED, Visibility::VISIBILITY_NOT_VISIBLE),
            $this->product(Status::STATUS_ENABLED, Visibility::VISIBILITY_BOTH, [2])
        );
        $this->assertNull($this->service->product(7, 1));
        $this->assertNull($this->service->product(7, 1));
        $this->assertNull($this->service->product(7, 1));
    }

    public function testItIgnoresMissingAndNonPositiveProductIds(): void
    {
        $products = $this->createMock(ProductRepositoryInterface::class);
        $this->fixture(products: $products);
        $products->expects($this->once())->method('getById')
            ->willThrowException(new NoSuchEntityException());
        $this->assertNull($this->service->product(0, 1));
        $this->assertNull($this->service->product(-3, 1));
        $this->assertNull($this->service->product(99, 1));
    }

    public function testItReportsThePriceInTheStoresCurrentCurrencyWithoutConvertingItAgain(): void
    {
        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $this->fixture(priceCurrency: $priceCurrency);
        $this->products->method('getById')->willReturn($this->product());
        $this->store->method('getCurrentCurrencyCode')->willReturn('GBP');
        $this->store->method('getBaseCurrencyCode')->willReturn('USD');
        $priceCurrency->expects($this->never())->method('convertAndFormat');
        $priceCurrency->expects($this->once())->method('format')
            ->with(19.5, false, PriceCurrencyInterface::DEFAULT_PRECISION, $this->store)
            ->willReturn('£19.50');

        $result = $this->service->product(7, 1);
        $this->assertSame('GBP', $result['currency']);
        $this->assertSame(19.5, $result['price']);
        $this->assertSame('£19.50', $result['priceFormatted']);
    }
}
