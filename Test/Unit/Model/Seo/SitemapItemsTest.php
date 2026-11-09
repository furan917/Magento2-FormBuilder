<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Seo;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Model\ResourceModel\Form\Collection;
use Furan\FormBuilder\Model\ResourceModel\Form\CollectionFactory;
use Furan\FormBuilder\Model\Seo\FormUrls;
use Furan\FormBuilder\Model\Seo\SitemapItems;
use Magento\Sitemap\Model\ItemProvider\ConfigReaderInterface;
use Magento\Sitemap\Model\SitemapItemInterface;
use Magento\Sitemap\Model\SitemapItemInterfaceFactory;
use PHPUnit\Framework\TestCase;

class SitemapItemsTest extends TestCase
{
    public function testItListsActiveFormsOfTheStoreExceptHiddenOnes(): void
    {
        $visible = $this->createStub(Form::class);
        $visible->method('getSettings')->willReturn(['seo_noindex' => false]);
        $visible->method('getUpdatedAt')->willReturn('2026-11-01 10:00:00');
        $hidden = $this->createStub(Form::class);
        $hidden->method('getSettings')->willReturn(['seo_noindex' => true]);

        $filters = [];
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $value) use (&$filters, $collection) {
            $filters[] = [$field, $value];
            return $collection;
        });
        $collection->expects($this->once())->method('addStoreFilter')->with(2)->willReturnSelf();
        $collection->expects($this->once())->method('addFieldToSelect')
            ->with(['form_id', 'identifier', 'settings_json', 'updated_at'])->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$visible, $hidden]));
        $collections = $this->createStub(CollectionFactory::class);
        $collections->method('create')->willReturn($collection);
        $urls = $this->createStub(FormUrls::class);
        $urls->method('path')->willReturn('forms/contact');
        $config = $this->createStub(ConfigReaderInterface::class);
        $config->method('getPriority')->willReturn('0.25');
        $config->method('getChangeFrequency')->willReturn('weekly');
        $created = [];
        $items = $this->createStub(SitemapItemInterfaceFactory::class);
        $items->method('create')->willReturnCallback(function (array $data) use (&$created) {
            $created[] = $data;
            return $this->createStub(SitemapItemInterface::class);
        });

        $result = (new SitemapItems($collections, $urls, $config, $items, $this->moduleConfig(true)))->getItems('2');

        $this->assertCount(1, $result);
        $this->assertSame([['is_active', 1]], $filters);
        $this->assertSame(
            [['url' => 'forms/contact', 'updatedAt' => '2026-11-01 10:00:00', 'priority' => '0.25', 'changeFrequency' => 'weekly']],
            $created
        );
    }

    public function testItListsNoFormsWhenFormBuilderIsSwitchedOffForTheStore(): void
    {
        $collections = $this->createMock(CollectionFactory::class);
        $collections->expects($this->never())->method('create');

        $result = (new SitemapItems(
            $collections,
            $this->createStub(FormUrls::class),
            $this->createStub(ConfigReaderInterface::class),
            $this->createStub(SitemapItemInterfaceFactory::class),
            $this->moduleConfig(false)
        ))->getItems('2');

        $this->assertSame([], $result);
    }

    private function moduleConfig(bool $enabled): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->with(2)->willReturn($enabled);
        return $config;
    }

    public function testItDeclaresTheSitemapModuleAsAHardDependency(): void
    {
        $root = dirname(__DIR__, 4);
        $composer = json_decode((string)file_get_contents($root . '/composer.json'), true);
        $this->assertArrayHasKey('magento/module-sitemap', $composer['require']);
        $this->assertArrayNotHasKey('magento/module-sitemap', $composer['suggest']);
        $module = simplexml_load_file($root . '/etc/module.xml');
        $this->assertCount(1, $module->xpath('/config/module/sequence/module[@name="Magento_Sitemap"]'));
    }
}
