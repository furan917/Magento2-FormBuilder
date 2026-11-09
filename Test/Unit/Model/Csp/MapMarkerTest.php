<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Csp;

use Furan\FormBuilder\Test\Unit\Helper\MapMarkerFactory;
use PHPUnit\Framework\TestCase;

class MapMarkerTest extends TestCase
{
    public function testItGivesEachStoreAStableSecretValue(): void
    {
        $value = MapMarkerFactory::create()->value(4);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $value);
        $this->assertSame($value, MapMarkerFactory::create()->value(4), 'stable, so cached pages keep matching');
        $this->assertNotSame($value, MapMarkerFactory::create()->value(5), 'store-scoped');
        $this->assertNotSame($value, MapMarkerFactory::create('other')->value(4), 'derived from the crypt key');
    }

    public function testItAcceptsTheAttributeOfEveryCryptKeyVersion(): void
    {
        $old = MapMarkerFactory::create('k0')->value(4);
        $new = MapMarkerFactory::create('k1')->value(4);

        $this->assertSame(
            ['data-ffb-map-canvas="' . $new . '"', 'data-ffb-map-canvas="' . $old . '"'],
            MapMarkerFactory::create('k0 k1')->attributes(4)
        );
        $this->assertSame($new, MapMarkerFactory::create('k0 k1')->value(4), 'renders with the newest key');
    }

    public function testItHasNoValueWithoutACryptKey(): void
    {
        $this->assertSame('', MapMarkerFactory::create('')->value(4));
        $this->assertSame([], MapMarkerFactory::create('')->attributes(4));
    }
}
