<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Plugin;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Observer\StorefrontSwitch;
use Furan\FormBuilder\Plugin\StorefrontSwitchBeforeValidation;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\ValidatorInterface;
use Magento\Framework\Exception\NotFoundException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class StorefrontSwitchBeforeValidationTest extends TestCase
{
    private function plugin(bool $enabled): StorefrontSwitchBeforeValidation
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        return new StorefrontSwitchBeforeValidation(new StorefrontSwitch($config, $stores));
    }

    private function validate(StorefrontSwitchBeforeValidation $plugin, string $module): ?array
    {
        $request = $this->createStub(Http::class);
        $request->method('getModuleName')->willReturn($module);
        return $plugin->beforeValidate(
            $this->createStub(ValidatorInterface::class),
            $request,
            $this->createStub(ActionInterface::class)
        );
    }

    public function testADisabledStoreAnswers404BeforeTheFormKeyCheck(): void
    {
        $this->expectException(NotFoundException::class);
        $this->validate($this->plugin(false), 'furanform');
    }

    public function testItLeavesEnabledStoresAndOtherModulesAlone(): void
    {
        $this->assertNull($this->validate($this->plugin(true), 'furanform'));
        $this->assertNull($this->validate($this->plugin(false), 'cms'));
    }

    public function testItIsWiredToTheStorefrontRequestValidator(): void
    {
        $di = simplexml_load_file(__DIR__ . '/../../../etc/frontend/di.xml');
        $this->assertCount(1, $di->xpath(
            '//type[@name="Magento\Framework\App\Request\ValidatorInterface"]/plugin[@type="'
            . StorefrontSwitchBeforeValidation::class . '"]'
        ));
    }
}
