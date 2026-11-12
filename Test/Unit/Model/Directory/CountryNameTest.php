<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Directory;

use Furan\FormBuilder\Model\Directory\CountryName;
use PHPUnit\Framework\TestCase;

class CountryNameTest extends TestCase
{
    public function testItNamesTheCountryInTheGivenLocale(): void
    {
        $this->assertSame('Royaume-Uni', CountryName::of('GB', 'fr_FR'));
        $this->assertSame('United Kingdom', CountryName::of('GB', 'en_GB'));
        $this->assertSame('Deutschland', CountryName::of('DE', 'de_DE'));
    }

    public function testItKeepsEachLocaleApartWithinOneProcess(): void
    {
        CountryName::of('FR', 'en_US');

        $this->assertSame('France', CountryName::of('FR', 'en_US'));
        $this->assertSame('Frankreich', CountryName::of('FR', 'de_DE'));
    }

    public function testItReturnsMalformedCodesUnchanged(): void
    {
        $this->assertSame('<b>', CountryName::of('<b>', 'fr_FR'));
        $this->assertSame('gb', CountryName::of('gb', 'fr_FR'));
    }
}
