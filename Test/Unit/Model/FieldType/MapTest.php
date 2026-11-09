<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\FieldType\Map;
use Furan\FormBuilder\Test\Unit\Helper\MapMarkerFactory;
use Furan\FormBuilder\Test\Unit\Helper\StorefrontUrlsFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class MapTest extends TestCase
{
    private Map $type;

    protected function setUp(): void
    {
        $this->type = $this->mapType([]);
    }

    /** @param array<string, mixed> $config Config getter => value */
    private function mapType(array $config): Map
    {
        $moduleConfig = $this->createStub(Config::class);
        foreach ($config as $getter => $value) {
            $moduleConfig->method($getter)->willReturn($value);
        }
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->with('general/locale/code', 'store', 4)->willReturn('en_GB');
        return new Map($moduleConfig, $scope, StorefrontUrlsFactory::create(), MapMarkerFactory::create());
    }

    public function testItRoundsLatitudeAndLongitudeToSixDecimals(): void
    {
        $this->assertSame(
            ['lat' => '51.507400', 'lng' => '-0.127800', 'zoom' => 16, 'label' => ''],
            $this->type->normalizeValue(['lat' => '51.5074', 'lng' => '-0.1278', 'zoom' => '16'], [])
        );
        $this->assertSame('1.234568', $this->type->normalizeValue(['lat' => '1.2345678', 'lng' => '0'], [])['lat']);
        $this->assertSame('0.000000', $this->type->normalizeValue(['lat' => '-0.0000001', 'lng' => '0'], [])['lat']);
    }

    public function testItWrapsLongitudeIntoTheMinus180To180Range(): void
    {
        $lng = fn(string $v) => $this->type->normalizeValue(['lat' => '0', 'lng' => $v], [])['lng'];
        $this->assertSame('180.000000', $lng('180'));
        $this->assertSame('-180.000000', $lng('-180'));
        $this->assertSame('-180.000000', $lng('540'));
        $this->assertSame('170.000000', $lng('-190'));
    }

    public function testItRejectsALatitudeOutsideMinus90To90(): void
    {
        $value = $this->type->normalizeValue(['lat' => '90.5', 'lng' => '0'], []);
        $this->assertNotNull($this->type->validateValue($value, [], []));
        $ok = $this->type->normalizeValue(['lat' => '-90', 'lng' => '0'], []);
        $this->assertNull($this->type->validateValue($ok, [], []));
    }

    public function testItRejectsNonNumericCoordinates(): void
    {
        foreach (['abc', '1e5', '.5', 'NaN', 'INF'] as $bad) {
            $value = $this->type->normalizeValue(['lat' => $bad, 'lng' => '0'], []);
            $this->assertSame('invalid', $value['error'], $bad);
            $this->assertNotNull($this->type->validateValue($value, [], []), $bad);
        }
        $this->assertNull($this->type->normalizeValue(['lat' => '', 'lng' => ' '], []));
        $this->assertNull($this->type->normalizeValue('x', []));
        $half = $this->type->normalizeValue(['lat' => '1', 'lng' => ''], []);
        $this->assertSame('incomplete', $half['error']);
        $this->assertNotNull($this->type->validateValue($half, [], []));
    }

    public function testItKeepsAnOptionalLabelOfAtMost255Characters(): void
    {
        $value = $this->type->normalizeValue(
            ['lat' => '1', 'lng' => '2', 'label' => " Home\n" . str_repeat('é', 300)],
            []
        );
        $this->assertSame('Home ' . str_repeat('é', 250), $value['label']);
        $this->assertSame(255, mb_strlen($value['label']));
    }

    public function testItDeclaresDefaultCentreZoomAndHeightSettings(): void
    {
        $spec = $this->type->getSettingsSpec();
        $this->assertSame(['type' => 'number', 'default' => 51.5, 'min' => -90, 'max' => 90], $spec['default_lat']);
        $this->assertSame(['type' => 'number', 'default' => 0, 'min' => -180, 'max' => 180], $spec['default_lng']);
        $this->assertSame(['type' => 'int', 'default' => 5, 'min' => 1, 'max' => 19], $spec['default_zoom']);
        $this->assertSame(['type' => 'int', 'default' => 320, 'min' => 200, 'max' => 800], $spec['height']);
        $this->assertSame([], $this->type->getAllowedRules());
    }

    public function testItReturnsTranslatedClientMessagesForEachMapError(): void
    {
        $messages = $this->type->getClientMessages([])['messages'];
        $this->assertSame(['invalid', 'incomplete'], array_keys($messages));
        $this->assertSame($messages['invalid'], $this->type->getInvalidMessage([]));
        $this->assertNotSame('', $messages['incomplete']);
    }

    public function testItProvidesTheMapSettingsAsStorefrontConfigForTheStore(): void
    {
        $osm = $this->mapType([
            'getMapProvider' => 'osm', 'getTileUrl' => 'https://tiles.test/{z}/{x}/{y}.png',
            'getAttribution' => '&copy; Tiles', 'getMaxZoom' => 18, 'getGoogleApiKey' => 'KEY',
            'getGoogleMapId' => 'MAPID', 'isMapSearchEnabled' => true,
        ])->storefrontConfig([], 4)['maps'];

        $this->assertSame('osm', $osm['provider']);
        $this->assertSame(18, $osm['maxZoom']);
        $this->assertSame('', $osm['googleKey']);
        $this->assertSame('', $osm['mapId'], 'Map ID only travels with the google provider');
        $this->assertSame('en_GB', $osm['locale']);
        $this->assertSame('https://shop.test/furanform/map/search', $osm['searchUrl']);
        $this->assertSame('https://static.test/Furan_FormBuilder/vendor/leaflet/1.9.4/images/', $osm['assets']['imagePath']);
        $this->assertStringContainsString('tiles.test', $osm['messages']['privacy']);
        $this->assertSame(MapMarkerFactory::create()->value(4), $osm['canvasMarker'], 'the store\'s CSP marker for the canvas');

        $google = $this->mapType([
            'getMapProvider' => 'google', 'getTileUrl' => '', 'getAttribution' => '', 'getMaxZoom' => 18,
            'getGoogleApiKey' => 'KEY', 'getGoogleMapId' => 'MAPID', 'isMapSearchEnabled' => true,
        ])->storefrontConfig([], 4)['maps'];
        $this->assertSame('KEY', $google['googleKey']);
        $this->assertSame('MAPID', $google['mapId']);
        $this->assertSame('', $google['searchUrl'], 'Search is osm only');
        $this->assertStringContainsString('Google', $google['messages']['privacy']);
    }
}
