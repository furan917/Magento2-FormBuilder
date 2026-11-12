<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Ui\Component\Listing\Column;

use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use Furan\FormBuilder\Ui\Component\Listing\Column\StoreView;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Store\Api\Data\GroupInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class StoreViewTest extends TestCase
{
    private int $storeLookups = 0;

    private function store(int $id, string $name, int $group, int $website): StoreInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getName')->willReturn($name);
        $store->method('getStoreGroupId')->willReturn($group);
        $store->method('getWebsiteId')->willReturn($website);
        return $store;
    }

    /** @template T of object @param class-string<T> $class @return T */
    private function named(string $class, int $id, string $name): object
    {
        $entity = $this->createStub($class);
        $entity->method('getId')->willReturn($id);
        $entity->method('getName')->willReturn($name);
        return $entity;
    }

    private function column(): StoreView
    {
        $stores = [
            1 => $this->store(1, 'Hyvä', 1, 1),
            2 => $this->store(2, '<b>Luma</b>', 2, 2),
        ];
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturnCallback(function () use ($stores): array {
            $this->storeLookups++;
            return $stores;
        });
        $manager->method('getWebsites')->willReturn([
            1 => $this->named(WebsiteInterface::class, 1, 'Main Website'),
            2 => $this->named(WebsiteInterface::class, 2, 'Site'),
        ]);
        $manager->method('getGroups')->willReturn([
            1 => $this->named(GroupInterface::class, 1, 'Main Website Store'),
            2 => $this->named(GroupInterface::class, 2, 'Shop "B"'),
        ]);
        return new StoreView(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $manager,
            EscaperFactory::create(),
            [],
            ['name' => 'store_id']
        );
    }

    public function testItShowsTheStoreViewNameWithItsFullPathAsATitle(): void
    {
        $result = $this->column()->prepareDataSource(['data' => ['items' => [
            ['submission_id' => 1, 'store_id' => '1'],
            ['submission_id' => 2, 'store_id' => 2],
            ['submission_id' => 3, 'store_id' => 9],
            ['submission_id' => 4, 'store_id' => null],
        ]]]);
        $items = $result['data']['items'];

        $this->assertSame('Hyvä', $this->title($items[0]['store_id'], 'Main Website / Main Website Store / Hyvä'));
        $this->assertSame('&lt;b&gt;Luma&lt;/b&gt;', $this->title($items[1]['store_id'], 'Site / Shop "B" / <b>Luma</b>'));
        $this->assertSame('', $items[2]['store_id']);
        $this->assertSame('', $items[3]['store_id']);
        $this->assertSame(1, $this->storeLookups);
    }

    /** Asserts the cell is one span whose decoded title is $title, and returns its (still escaped) text. */
    private function title(string $cell, string $title): string
    {
        $this->assertSame(1, preg_match('#^<span title="([^"<>]*)">([^<>]*)</span>$#D', $cell, $match), $cell);
        $this->assertSame($title, html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5));
        return $match[2];
    }

    public function testItLeavesAnEmptyPageAlone(): void
    {
        $this->assertSame(['data' => ['items' => []]], $this->column()->prepareDataSource(['data' => ['items' => []]]));
    }
}
