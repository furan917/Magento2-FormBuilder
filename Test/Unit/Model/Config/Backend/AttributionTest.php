<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Config\Backend;

use Furan\FormBuilder\Model\Config\Backend\Attribution;
use Furan\FormBuilder\Model\Map\AttributionSanitizer;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

class AttributionTest extends TestCase
{
    private function context(): Context
    {
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(ManagerInterface::class));

        return $context;
    }

    public function testItSanitisesTheAttributionOnSave(): void
    {
        $model = new Attribution(
            $this->context(),
            $this->createStub(Registry::class),
            $this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class),
            $this->createStub(\Magento\Framework\App\Cache\TypeListInterface::class),
            new AttributionSanitizer()
        );
        $model->setValue('<b onclick="x">OSM</b> <a href="javascript:x">y</a>');
        $model->beforeSave();

        $this->assertSame('OSM y', $model->getValue());
    }
}
