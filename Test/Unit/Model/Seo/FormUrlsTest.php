<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Seo;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Seo\FormUrls;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class FormUrlsTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private ?UrlFactory $urlFactory = null;
    /** @var array<string, mixed> */
    private array $stores = [];

    /** @param array<int, array{0: string, 1: string, 2?: bool}> $stores id => [base url, locale, active] */
    private function urls(array $stores, int $defaultId = 1): FormUrls
    {
        $models = [];
        $this->urlFactory ??= $this->createStub(UrlFactory::class);
        foreach ($stores as $id => [$base, $locale]) {
            $store = $this->createStub(Store::class);
            $store->method('getId')->willReturn($id);
            $store->method('isActive')->willReturn($stores[$id][2] ?? true);
            $store->method('getBaseUrl')->willReturn($base);
            $models[$id] = $store;
        }
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn($models);
        $manager->method('getStore')->willReturnCallback(fn($id) => $models[(int)$id]);
        $manager->method('getDefaultStoreView')->willReturn($models[$defaultId]);
        $config = $this->createStub(Config::class);
        $config->method('getRoutePrefix')->willReturn('forms');
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn($path, $type, $id) => $stores[(int)$id][1]);
        $this->stores = $models;
        return new FormUrls($manager, $config, $scope, $this->urlFactory);
    }

    private function form(array $storeIds): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getIdentifier')->willReturn('contact');
        $form->method('getStoreIds')->willReturn($storeIds);
        return $form;
    }

    public function testItLinksEveryLanguageVersionAndTheDefault(): void
    {
        $urls = $this->urls([
            1 => ['https://shop.test/', 'en_GB'],
            2 => ['https://shop.test/fr/', 'fr_FR'],
            3 => ['https://shop.test/de/', 'de_DE', false],
        ]);

        $this->assertSame('https://shop.test/fr/forms/contact', $urls->url($this->form([0]), 2));
        $this->assertSame([
            'en-gb' => 'https://shop.test/forms/contact',
            'fr-fr' => 'https://shop.test/fr/forms/contact',
            'x-default' => 'https://shop.test/forms/contact',
        ], $urls->alternates($this->form([0])));
    }

    public function testItAddsNoAlternatesWhenTheFormIsOnOneStoreOrStoresShareAUrl(): void
    {
        $split = $this->urls([1 => ['https://shop.test/', 'en_GB'], 2 => ['https://shop.test/fr/', 'fr_FR']]);
        $this->assertSame([], $split->alternates($this->form([2])));

        $shared = $this->urls([1 => ['https://shop.test/', 'en_GB'], 2 => ['https://shop.test/', 'fr_FR']]);
        $this->assertSame([], $shared->alternates($this->form([0])));
    }

    public function testItKeepsCanonicalAndHreflangUrlsFreeOfTheStoreQuery(): void
    {
        $this->asserted = ['urlFactory'];
        $this->urlFactory = $this->double('urlFactory', UrlFactory::class);
        $this->urlFactory->expects($this->never())->method('create');
        $urls = $this->urls([1 => ['https://shop.test/', 'en_GB'], 2 => ['https://shop.test/fr/', 'fr_FR']]);

        $this->assertStringNotContainsString('?', $urls->url($this->form([0]), 2));
        foreach ($urls->alternates($this->form([0])) as $link) {
            $this->assertStringNotContainsString('?', $link);
        }
    }

    public function testItBuildsTheStoreUrlThroughAFreshFrontendUrlScopedToTheStoreObject(): void
    {
        $this->asserted = ['urlFactory'];
        $url = $this->createMock(UrlInterface::class);
        $this->urlFactory = $this->double('urlFactory', UrlFactory::class);
        $this->urlFactory->expects($this->once())->method('create')->willReturn($url);
        $urls = $this->urls([1 => ['https://shop.test/', 'en_GB'], 2 => ['https://shop.test/fr/', 'fr_FR']]);
        $url->expects($this->once())->method('setScope')->with($this->stores[2]);
        $url->expects($this->once())->method('getUrl')
            ->with('', ['_direct' => 'forms/contact', '_scope_to_url' => true, '_nosid' => true])
            ->willReturn('https://shop.test/fr/forms/contact?___store=fr');

        $this->assertSame('https://shop.test/fr/forms/contact?___store=fr', $urls->storeUrl($this->form([0]), 2));
    }
}
