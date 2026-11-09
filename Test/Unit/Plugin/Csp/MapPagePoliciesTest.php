<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Plugin\Csp;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Csp\MapPolicies;
use Furan\FormBuilder\Plugin\Csp\MapPagePolicies;
use Furan\FormBuilder\Test\Unit\Helper\MapMarkerFactory;
use Magento\Csp\Api\CspRendererInterface;
use Magento\Csp\Model\Collector\DynamicCollector;
use Magento\Framework\App\Response\Http;
use Magento\Framework\App\Response\HttpInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class MapPagePoliciesTest extends TestCase
{
    private function plugin(DynamicCollector $collector, string $cryptKey = 'k0'): MapPagePolicies
    {
        $config = $this->createStub(Config::class);
        $config->method('getMapProvider')->willReturn('osm');
        $config->method('getTileUrl')->willReturnCallback(
            static fn (int $storeId): string => $storeId === 4 ? 'https://tiles.example.org/{z}/{x}/{y}.png' : ''
        );
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(4);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        return new MapPagePolicies(new MapPolicies($config), $collector, $stores, MapMarkerFactory::create($cryptKey));
    }

    private function response(string $body): Http
    {
        $response = $this->createStub(Http::class);
        $response->method('getContent')->willReturn($body);

        return $response;
    }

    public function testItAllowsTheMapHostsOnAPageThatRendersAMapField(): void
    {
        $collector = $this->createMock(DynamicCollector::class);
        $collector->expects($this->once())->method('add')->with($this->callback(
            static fn ($policy): bool => $policy->getId() === 'img-src' && $policy->getHostSources() === ['tiles.example.org']
        ));

        $this->plugin($collector)->beforeRender(
            $this->createStub(CspRendererInterface::class),
            $this->response('<form><div class="ffb-map-canvas" data-ffb-map-canvas="' . MapMarkerFactory::create()->value(4)
                . '" role="application"></div></form>')
        );
    }

    public function testItIgnoresAMarkerThatVisitorTextCouldPutOnThePage(): void
    {
        $collector = $this->createMock(DynamicCollector::class);
        $collector->expects($this->never())->method('add');
        $value = MapMarkerFactory::create()->value(4);

        foreach ([
            '<p>Results for data-ffb-map-canvas</p>',
            '<div data-ffb-map-canvas></div>',
            '<div data-ffb-map-canvas="0123456789abcdef0123456789abcdef"></div>',
            '<p>data-ffb-map-canvas=&quot;' . $value . '&quot;</p>',
            '<div data-ffb-map-canvas="' . MapMarkerFactory::create()->value(5) . '"></div>',
        ] as $body) {
            $this->plugin($collector)->beforeRender($this->createStub(CspRendererInterface::class), $this->response($body));
        }
    }

    public function testItStillMatchesPagesCachedBeforeTheCryptKeyWasRotated(): void
    {
        $collector = $this->createMock(DynamicCollector::class);
        $collector->expects($this->once())->method('add');

        $this->plugin($collector, 'k0 k1')->beforeRender(
            $this->createStub(CspRendererInterface::class),
            $this->response('<div data-ffb-map-canvas="' . MapMarkerFactory::create('k0')->value(4) . '"></div>')
        );
    }

    public function testItLeavesThePolicyAloneOnAPageWithoutAMapField(): void
    {
        $collector = $this->createMock(DynamicCollector::class);
        $collector->expects($this->never())->method('add');

        $this->plugin($collector)->beforeRender($this->createStub(CspRendererInterface::class), $this->response('<form></form>'));
        $this->plugin($collector)->beforeRender(
            $this->createStub(CspRendererInterface::class),
            $this->createStub(HttpInterface::class)
        );
    }

    public function testItIsAFrontendPluginOnTheCspRendererAndNoLongerAGlobalCollector(): void
    {
        $etc = __DIR__ . '/../../../../etc/';
        $frontend = simplexml_load_file($etc . 'frontend/di.xml')->xpath(
            '/config/type[@name="Magento\Csp\Api\CspRendererInterface"]/plugin[@name="furan_formbuilder_map_policies"]'
        );

        $this->assertCount(1, $frontend);
        $this->assertSame(MapPagePolicies::class, (string)$frontend[0]['type']);
        $this->assertStringNotContainsString('Furan\FormBuilder\Model\Csp', (string)file_get_contents($etc . 'di.xml'));
    }
}
