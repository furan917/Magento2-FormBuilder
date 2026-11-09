<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Model\FieldType\Map;
use PHPUnit\Framework\TestCase;

/** Runs the cases shared with the JS map parity tests. */
class MapFixtureTest extends TestCase
{
    public function testItPassesEveryCaseInTheSharedMapValuesFixture(): void
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/map-values.json'), true);
        $this->assertNotEmpty($cases);
        $type = new Map($this->createStub(\Furan\FormBuilder\Model\Config::class), $this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class), \Furan\FormBuilder\Test\Unit\Helper\StorefrontUrlsFactory::create(), \Furan\FormBuilder\Test\Unit\Helper\MapMarkerFactory::create());
        foreach ($cases as $case) {
            $value = $type->normalizeValue($case['raw'], $case['field']);
            $this->assertSame($case['normalized'], $value, $case['name']);
            $valid = $value === null
                ? !$case['field']['required']
                : $type->validateValue($value, $case['field'], []) === null;
            $this->assertSame($case['valid'], $valid, $case['name']);
        }
    }
}
