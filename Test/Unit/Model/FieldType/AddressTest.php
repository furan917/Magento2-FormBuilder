<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Model\Directory\AddressRules;
use Furan\FormBuilder\Model\FieldType\Address;
use PHPUnit\Framework\TestCase;

class AddressTest extends TestCase
{
    private function type(?AddressRules $rules = null): Address
    {
        return new Address($rules ?? $this->createStub(AddressRules::class), \Furan\FormBuilder\Test\Unit\Helper\StorefrontUrlsFactory::create());
    }

    public function testItNormalisesPostedAddressPartsIntoTheDocumentedShape(): void
    {
        $value = $this->type()->normalizeValue([
            'street' => [' 1 Main St ', '', 'Flat 2'],
            'city' => ' Leeds ',
            'region_id' => '12',
            'region' => '',
            'postcode' => "  ls1   4ab ",
            'country_id' => 'GB',
        ], []);
        $this->assertSame([
            'street' => ['1 Main St', 'Flat 2'],
            'city' => 'Leeds',
            'region_id' => '12',
            'region' => '',
            'postcode' => 'LS1 4AB',
            'country_id' => 'GB',
        ], $value);
    }

    public function testItLimitsStreetLinesToTheConfiguredNumber(): void
    {
        $type = $this->type();
        $value = $type->normalizeValue(['street' => ['a', 'b', 'c', 'd', 'e'], 'city' => 'x', 'country_id' => 'GB'], []);
        $this->assertSame(['a', 'b', 'c', 'd'], $value['street']);
        $this->assertSame('', $value['region_id']);
        $this->assertStringContainsString(
            'street',
            (string)(new AddressRules(
                $this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class),
                $this->createStub(\Magento\Directory\Model\AllowedCountries::class),
                $this->createStub(\Magento\Directory\Model\Country\Postcode\ConfigInterface::class),
                $this->createStub(\Magento\Directory\Model\ResourceModel\Region\CollectionFactory::class)
            ))->validate(
                ['street' => ['a', 'b', 'c'], 'city' => 'x', 'region_id' => '', 'region' => '', 'postcode' => '1',
                    'country_id' => 'GB'],
                [],
                ['allowedCountries' => ['GB'], 'regionsRequired' => [], 'optionalZip' => [], 'postcodes' => [],
                    'streetLines' => 2, 'regions' => []]
            )
        );
    }

    public function testItTreatsAnAddressWithOnlyACountryAsEmpty(): void
    {
        $this->assertNull($this->type()->normalizeValue(
            ['street' => ['', ' '], 'city' => ' ', 'country_id' => 'GB'],
            []
        ));
        $this->assertNull($this->type()->normalizeValue('nope', []));
    }

    public function testItDelegatesValidationToTheRulesWithTheStoreDirectory(): void
    {
        $rules = $this->createMock(AddressRules::class);
        $directory = ['allowedCountries' => ['GB']];
        $value = ['street' => ['a'], 'city' => 'b', 'region_id' => '', 'region' => '', 'postcode' => '', 'country_id' => 'GB'];
        $rules->expects($this->once())->method('getDirectoryData')->with(3, ['GB'])->willReturn($directory);
        $rules->expects($this->once())->method('validate')->with($value, [], $directory)->willReturn('Bad.');
        $this->assertSame('Bad.', $this->type($rules)->validateValue($value, [], ['store_id' => 3]));
    }

    public function testItReturnsTranslatedClientMessagesForEachAddressError(): void
    {
        $messages = $this->type()->getClientMessages([])['messages'];
        $this->assertSame(
            ['country', 'street', 'city', 'region', 'postcode', 'lines', 'postcode_hint'],
            array_keys($messages)
        );
        foreach ($messages as $message) {
            $this->assertNotSame('', $message);
        }
    }

    public function testItHasNoRulesAndIsAnInputType(): void
    {
        $this->assertSame('address', $this->type()->getCode());
        $this->assertSame([], $this->type()->getAllowedRules());
    }

    public function testItProvidesTheDirectoryWithoutRegionsAndTheRegionsUrlAsStorefrontConfig(): void
    {
        $rules = $this->createMock(AddressRules::class);
        $rules->expects($this->once())->method('getDirectoryData')->with(4)->willReturn(
            ['allowedCountries' => ['GB'], 'streetLines' => 3, 'regions' => ['GB' => ['1' => 'x']]]
        );

        $this->assertSame(
            [
                'directory' => ['allowedCountries' => ['GB'], 'streetLines' => 3],
                'regionsUrl' => 'https://shop.test/furanform/directory/regions',
            ],
            $this->type($rules)->storefrontConfig([['name' => 'addr']], 4)
        );
    }
}
