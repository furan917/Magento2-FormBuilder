<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Config\Backend;

use Furan\FormBuilder\Model\Config\Backend\TileUrl;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

class TileUrlTest extends TestCase
{
    private function context(): Context
    {
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(ManagerInterface::class));

        return $context;
    }

    private function save(string $value): void
    {
        $model = new TileUrl(
            $this->context(),
            $this->createStub(Registry::class),
            $this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class),
            $this->createStub(\Magento\Framework\App\Cache\TypeListInterface::class)
        );
        $model->setValue($value);
        $model->beforeSave();
    }

    public function testItRejectsATileUrlThatIsNotHttps(): void
    {
        $this->expectException(LocalizedException::class);
        $this->save('http://tile.test/{z}/{x}/{y}.png');
    }

    public function testItRejectsATileUrlWithoutZXAndYPlaceholders(): void
    {
        $this->expectException(LocalizedException::class);
        $this->save('https://tile.test/{z}/{x}.png');
    }

    public function testItRejectsATileUrlWithPlaceholdersOtherThanZXYSRAndMinusY(): void
    {
        $this->expectException(LocalizedException::class);
        $this->save('https://tile.test/{z}/{x}/{y}.png?k={key}');
    }

    public function testItAcceptsValidTemplates(): void
    {
        $this->save('https://{s}.tile.test/{z}/{x}/{y}{r}.png');
        $this->save('https://tile.test/{z}/{x}/{-y}.png');
        $this->save('');
        $this->addToAssertionCount(1);
    }
}
