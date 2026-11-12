<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Theme;

use Furan\FormBuilder\Model\Theme\HyvaDetector;
use Magento\Framework\View\Design\ThemeInterface;
use Magento\Framework\View\DesignInterface;
use PHPUnit\Framework\TestCase;

class HyvaDetectorTest extends TestCase
{
    public function testItFindsHyvaAnywhereInTheCurrentThemesAncestry(): void
    {
        $this->assertTrue($this->detector($this->theme('Vendor/child', $this->theme('Hyva/default', $this->theme('Hyva/reset'))))->isHyva());
        $this->assertTrue($this->detector($this->theme('Hyva/default'))->isHyva());
    }

    public function testItIsFalseForLumaAndForNoTheme(): void
    {
        $this->assertFalse($this->detector($this->theme('Vendor/shop', $this->theme('Magento/luma', $this->theme('Magento/blank'))))->isHyva());
        $this->assertFalse($this->detector($this->theme(''))->isHyva());
        $this->assertFalse($this->detector($this->theme('hyva/default'))->isHyva(), 'theme codes are case-sensitive');
    }

    public function testItAsksTheDesignOnlyOncePerRequest(): void
    {
        $design = $this->createMock(DesignInterface::class);
        $design->expects($this->once())->method('getDesignTheme')->willReturn($this->theme('Hyva/default'));
        $detector = new HyvaDetector($design);
        $this->assertTrue($detector->isHyva());
        $this->assertTrue($detector->isHyva());
    }

    public function testItStopsOnAParentLoop(): void
    {
        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getCode')->willReturn('Vendor/loop');
        $theme->method('getParentTheme')->willReturn($theme);
        $this->assertFalse($this->detector($theme)->isHyva());
    }

    private function detector(ThemeInterface $theme): HyvaDetector
    {
        $design = $this->createStub(DesignInterface::class);
        $design->method('getDesignTheme')->willReturn($theme);
        return new HyvaDetector($design);
    }

    private function theme(string $code, ?ThemeInterface $parent = null): ThemeInterface
    {
        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getCode')->willReturn($code);
        $theme->method('getParentTheme')->willReturn($parent);
        return $theme;
    }
}
