<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Observer;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Observer\StorefrontSwitch;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\NotFoundException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class StorefrontSwitchTest extends TestCase
{
    private function event(string $module): Observer
    {
        $request = $this->createStub(Http::class);
        $request->method('getModuleName')->willReturn($module);

        return new Observer(['event' => new Event(['request' => $request])]);
    }

    private function observer(bool $enabled, int $checks = 1): StorefrontSwitch
    {
        $config = $this->createMock(Config::class);
        $config->expects($this->exactly($checks))->method('isEnabled')->with(4)->willReturn($enabled);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(4);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        return new StorefrontSwitch($config, $stores);
    }

    public function testItLetsModuleRoutesThroughWhenFormsAreEnabledForTheStore(): void
    {
        $this->observer(true)->execute($this->event('furanform'));
        $this->addToAssertionCount(1);
    }

    public function testItTurnsEveryModuleRouteIntoANotFoundWhenFormsAreDisabledForTheStore(): void
    {
        $this->expectException(NotFoundException::class);
        $this->observer(false)->execute($this->event('furanform'));
    }

    public function testItLeavesTheForwardedNotFoundPageAloneSoItCannotLoop(): void
    {
        $this->observer(false, 0)->execute($this->event('cms'));
        $this->addToAssertionCount(1);
    }

    public function testItIsWiredToEveryStorefrontRouteOfTheModule(): void
    {
        $events = simplexml_load_file(__DIR__ . '/../../../etc/frontend/events.xml');
        $this->assertCount(1, $events->xpath(
            '//event[@name="controller_action_predispatch_furanform"]/observer[@instance="' . StorefrontSwitch::class . '"]'
        ));
    }
}
