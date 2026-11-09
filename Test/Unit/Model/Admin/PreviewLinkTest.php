<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Admin;

use Furan\FormBuilder\Model\Admin\PreviewLink;
use Furan\FormBuilder\Model\Form\PreviewToken;
use Furan\FormBuilder\Model\Security\SigningKeys;
use Furan\FormBuilder\Model\Seo\FormUrls;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Url;
use Magento\Framework\UrlFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class PreviewLinkTest extends TestCase
{
    /** @var array<int, array{string, array<string, mixed>, mixed}> */
    private array $built = [];

    private function link(string $cryptKey = 'k0'): PreviewLink
    {
        $formUrls = $this->createStub(FormUrls::class);
        $formUrls->method('storeUrlFor')->willReturnCallback(
            static fn (string $identifier, int $storeId): string => 'https://shop.test/' . $storeId . '/forms/' . $identifier
        );
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturn($cryptKey);
        $store = $this->createStub(Store::class);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $urlFactory = $this->createStub(UrlFactory::class);
        $urlFactory->method('create')->willReturnCallback(function () use ($store): Url {
            $scope = null;
            $url = $this->createStub(Url::class);
            $url->method('setScope')->willReturnCallback(function ($value) use (&$scope, $url) {
                $scope = $value;
                return $url;
            });
            $url->method('getUrl')->willReturnCallback(function (string $route, array $params) use (&$scope): string {
                $this->built[] = [$route, $params, $scope];
                return 'https://shop.test/furanform/form/preview?' . http_build_query($params['_query']);
            });
            return $url;
        });

        return new PreviewLink($formUrls, new PreviewToken(new SigningKeys($config)), $storeManager, $urlFactory);
    }

    public function testItGivesAnActiveFormItsPublicUrl(): void
    {
        $this->assertSame('https://shop.test/2/forms/contact', $this->link()->url(7, 'contact', true, 2));
        $this->assertSame([], $this->built);
    }

    public function testItGivesAnInactiveFormASignedPreviewUrlOnItsStore(): void
    {
        $url = (string)$this->link()->url(7, 'contact', false, 2);

        $this->assertStringStartsWith('https://shop.test/furanform/form/preview?id=7&token=', $url);
        [$route, $params, $scope] = $this->built[0];
        $this->assertSame('furanform/form/preview', $route);
        $this->assertTrue($params['_nosid']);
        $this->assertNotNull($scope);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturn('k0');
        $this->assertTrue((new PreviewToken(new SigningKeys($config)))->verify((string)$query['token'], 7));
    }

    public function testItOffersNoPreviewOfAnInactiveFormWithoutACryptKey(): void
    {
        $this->assertNull($this->link('')->url(7, 'contact', false, 2));
    }
}
