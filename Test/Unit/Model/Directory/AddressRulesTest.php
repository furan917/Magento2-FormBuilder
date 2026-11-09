<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Directory;

use Furan\FormBuilder\Model\Directory\AddressRules;
use Magento\Directory\Model\AllowedCountries;
use Magento\Directory\Model\Country\Postcode\ConfigInterface;
use Magento\Directory\Model\ResourceModel\Region\Collection;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;

class AddressRulesTest extends TestCase
{
    private function rules(): AddressRules
    {
        return new AddressRules(
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(AllowedCountries::class),
            $this->createStub(ConfigInterface::class),
            $this->createStub(CollectionFactory::class)
        );
    }

    private function directory(array $override = []): array
    {
        return $override + [
            'allowedCountries' => ['GB', 'US', 'IE'],
            'regionsRequired' => ['US'],
            'optionalZip' => ['IE'],
            'postcodes' => ['GB' => ['^[A-Z]{1,2}\d'], 'US' => ['^\d{5}$']],
            'streetLines' => 2,
            'regions' => ['US' => ['12' => 'California']],
        ];
    }

    private function address(array $override = []): array
    {
        return $override + [
            'street' => ['1 Main St'], 'city' => 'Leeds', 'region_id' => '', 'region' => '',
            'postcode' => 'LS1 4AB', 'country_id' => 'GB',
        ];
    }

    public function testItRejectsACountryNotAllowedForTheStore(): void
    {
        $this->assertNull($this->rules()->validate($this->address(), [], $this->directory()));
        $this->assertNotNull($this->rules()->validate($this->address(['country_id' => 'FR']), [], $this->directory()));
        $this->assertNotNull($this->rules()->validate($this->address(['country_id' => '']), [], $this->directory()));
    }

    public function testItRequiresAStreetAndCity(): void
    {
        $this->assertNotNull($this->rules()->validate($this->address(['street' => []]), [], $this->directory()));
        $this->assertNotNull($this->rules()->validate($this->address(['city' => '']), [], $this->directory()));
    }

    public function testItRequiresARegionForCountriesWithRequiredStates(): void
    {
        $us = $this->address(['country_id' => 'US', 'postcode' => '90001']);
        $rules = $this->rules();
        $this->assertNotNull($rules->validate($us, [], $this->directory()));
        $this->assertNull($rules->validate(['region_id' => '12'] + $us, [], $this->directory()));
        $this->assertNotNull($rules->validate(['region_id' => '99'] + $us, [], $this->directory()));
        $noList = $this->directory(['regions' => [], 'regionsRequired' => ['US']]);
        $this->assertNotNull($rules->validate($us, [], $noList));
        $this->assertNull($rules->validate(['region' => 'Texas'] + $us, [], $noList));
    }

    public function testItAcceptsAMissingPostcodeForOptionalZipCountries(): void
    {
        $rules = $this->rules();
        $this->assertNull($rules->validate($this->address(['country_id' => 'IE', 'postcode' => '']), [], $this->directory()));
        $this->assertNotNull($rules->validate($this->address(['postcode' => '']), [], $this->directory()));
    }

    public function testItAcceptsAPostcodeThatDoesNotMatchTheCountryPatterns(): void
    {
        $this->assertNull($this->rules()->validate($this->address(['postcode' => '12345']), [], $this->directory()));
    }

    public function testItReportsAPostcodeMismatchAsANonBlockingWarning(): void
    {
        $rules = $this->rules();
        $this->assertTrue($rules->postcodeWarning($this->address(['postcode' => '12345']), $this->directory()));
        $this->assertFalse($rules->postcodeWarning($this->address(), $this->directory()));
        $this->assertFalse($rules->postcodeWarning($this->address(['postcode' => '']), $this->directory()));
        $this->assertFalse($rules->postcodeWarning($this->address(['country_id' => 'IE', 'postcode' => 'x']), $this->directory()));
    }

    public function testItLimitsStreetLinesToTheConfiguredNumber(): void
    {
        $street = ['a', 'b', 'c'];
        $this->assertNotNull($this->rules()->validate($this->address(['street' => $street]), [], $this->directory()));
        $this->assertNull($this->rules()->validate(
            $this->address(['street' => $street]),
            [],
            $this->directory(['streetLines' => 3])
        ));
    }

    public function testItBuildsDirectoryDataForAnExplicitStore(): void
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            static fn (string $path, string $type, $store) => $store === 5 ? [
                'general/region/state_required' => 'US,DE',
                'general/country/optional_zip_countries' => 'IE,GB',
                'customer/address/street_lines' => '3',
            ][$path] ?? null : null
        );
        $allowed = $this->createMock(AllowedCountries::class);
        $allowed->expects($this->once())->method('getAllowedCountries')->with('store', 5)
            ->willReturn(['GB' => 'United Kingdom', 'US' => 'United States']);
        $postcodes = $this->createStub(ConfigInterface::class);
        $postcodes->method('getPostCodes')->willReturn([
            'GB' => ['default' => ['pattern' => '^G$', 'example' => 'G']],
            'FR' => ['default' => ['pattern' => '^F$', 'example' => 'F']],
        ]);
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())->method('addCountryFilter')->with('US')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            new DataObject(['region_id' => '12', 'name' => 'California']),
        ]));
        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects($this->once())->method('create')->willReturn($collection);

        $data = (new AddressRules($scope, $allowed, $postcodes, $factory))->getDirectoryData(5, ['US', 'FR']);

        $this->assertSame([
            'allowedCountries' => ['GB', 'US'],
            'regionsRequired' => ['US'],
            'optionalZip' => ['GB'],
            'postcodes' => ['GB' => ['^G$']],
            'streetLines' => 3,
            'regions' => ['US' => ['12' => 'California']],
        ], $data);
    }
}
