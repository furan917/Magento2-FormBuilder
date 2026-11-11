<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class StorefrontStylesTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../view/frontend/web/css/ffb.css';

    private function rule(string $selector): string
    {
        $css = (string)file_get_contents(self::CSS);
        $this->assertSame(1, preg_match('/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/', $css, $m), $selector);
        return $m[1];
    }

    public function testItGivesTheColourInputAFixedHeightSoTheSwatchDoesNotCollapse(): void
    {
        $this->assertMatchesRegularExpression('/(^|;|\s)height:\s*var\(--ffb-control-h\)/', $this->rule('.ffb-skin .ffb-input[type="color"]'));
    }

    public function testItClearsThemeFieldsetMarginsSoGroupedFieldsUseTheGridGap(): void
    {
        $this->assertMatchesRegularExpression('/(^|;|\s)margin:\s*0;/', $this->rule('.ffb fieldset.ffb-field'));
    }

    public function testItSuppressesThemeSwitchThumbsSoTheToggleDrawsOneKnob(): void
    {
        $this->assertMatchesRegularExpression('/content:\s*none/', $this->rule('.ffb-skin input.ffb-choice.ffb-switch[type="checkbox"]::after'));
    }
}
