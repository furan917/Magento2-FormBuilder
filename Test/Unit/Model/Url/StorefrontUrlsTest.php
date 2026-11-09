<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Url;

use Furan\FormBuilder\Model\Url\StorefrontUrls;
use Magento\Framework\App\Request\Http;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository;
use PHPUnit\Framework\TestCase;

class StorefrontUrlsTest extends TestCase
{
    public function testItBuildsFieldTypeRouteUrlsWithTheStoreMarker(): void
    {
        $url = $this->createMock(UrlInterface::class);
        $url->expects($this->exactly(2))->method('getUrl')->willReturnCallback(
            function (string $path, array $params): string {
                $this->assertTrue($params['_scope_to_url']);
                $this->assertArrayNotHasKey('_scope', $params);
                return 'https://shop.test/' . $path;
            }
        );
        $request = $this->createStub(Http::class);
        $urls = new StorefrontUrls($url, $request, $this->createStub(Repository::class));

        $this->assertSame('https://shop.test/furanform/directory/regions', $urls->route('furanform/directory/regions'));
        $urls->route('furanform/map/search', ['form_id' => 3]);
    }
}
