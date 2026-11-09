<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Model\Directory\AddressRules;
use Furan\FormBuilder\Model\FieldType\Address;
use Magento\Directory\Model\AllowedCountries;
use Magento\Directory\Model\Country\Postcode\ConfigInterface;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

/** Cases shared with Test/js/address.test.js. */
class AddressFixtureTest extends TestCase
{
    public function testItPassesEveryCaseInTheSharedAddressValuesFixture(): void
    {
        $rules = new AddressRules(
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(AllowedCountries::class),
            $this->createStub(ConfigInterface::class),
            $this->createStub(CollectionFactory::class)
        );
        $type = new Address($rules, \Furan\FormBuilder\Test\Unit\Helper\StorefrontUrlsFactory::create());
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/address-values.json'), true);
        $this->assertNotEmpty($cases);
        foreach ($cases as $case) {
            $value = $type->normalizeValue($case['raw'], $case['field']) ?? '';
            $this->assertSame($case['normalized'], $value, $case['name']);
            if ($value === '') {
                $this->assertSame($case['valid'], !$case['field']['required'], $case['name']);
                continue;
            }
            $error = $rules->validate($value, $case['field'], $case['directory']);
            $this->assertSame($case['valid'], $error === null, $case['name']);
            $this->assertSame(
                ($case['warning'] ?? null) === 'postcode',
                $rules->postcodeWarning($value, $case['directory']),
                $case['name']
            );
        }
    }
}
