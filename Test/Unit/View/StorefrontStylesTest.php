<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class StorefrontStylesTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../view/frontend/web/css/ffb.css';
    private const HYVA_CSS = __DIR__ . '/../../../view/frontend/web/css/ffb-hyva.css';

    private function rule(string $selector): string
    {
        $css = (string)file_get_contents(self::CSS);
        $this->assertSame(1, preg_match('/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/', $css, $m), $selector);
        return $m[1];
    }

    /** Declarations of every rule (at any @media/@container level) whose selector list contains $selector. */
    private function declarations(string $selector, string $at = '', string $file = self::CSS): string
    {
        $css = (string)preg_replace('~/\*.*?\*/~s', '', (string)file_get_contents($file));
        $found = [];
        $context = [];
        $offset = 0;
        while (preg_match('/([^{}]*)([{}])/', $css, $m, 0, $offset) === 1) {
            $offset += strlen($m[0]);
            if ($m[2] === '}') {
                array_pop($context);
                continue;
            }
            $head = trim($m[1]);
            if (str_starts_with($head, '@')) {
                $context[] = $head;
                continue;
            }
            $end = strpos($css, '}', $offset);
            $body = substr($css, $offset, (int)$end - $offset);
            $offset = (int)$end + 1;
            $inside = implode(' ', $context);
            $selectors = array_map('trim', $this->splitSelectors($head));
            if (in_array($selector, $selectors, true) && ($at === '' ? $inside === '' : str_contains($inside, $at))) {
                $found[] = $body;
            }
        }
        $this->assertNotSame([], $found, $selector . ' ' . $at);
        return implode(';', $found);
    }

    /** @return string[] top-level selectors of a comma list (commas inside :is()/:where() stay) */
    private function splitSelectors(string $list): array
    {
        $parts = [''];
        $depth = 0;
        foreach (str_split($list) as $char) {
            $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
            if ($char === ',' && $depth === 0) {
                $parts[] = '';
                continue;
            }
            $parts[count($parts) - 1] .= $char;
        }
        return $parts;
    }

    /** font-size in em, resolving a var(--ffb-fs-*) against the scale on .ffb */
    private function emSize(string $selector, string $file = self::CSS): float
    {
        $declarations = $this->declarations($selector, '', $file);
        if (preg_match('/font-size:\s*var\((--ffb-fs-[a-z0-9]+)\)/', $declarations, $var) === 1) {
            $declarations = $this->declarations('.ffb');
            $this->assertSame(1, preg_match('/' . $var[1] . ':\s*([0-9.]+)em/', $declarations, $m), $var[1]);
            return (float)$m[1];
        }
        $this->assertSame(1, preg_match('/font-size:\s*([0-9.]+)em/', $declarations, $m), $selector);
        return (float)$m[1];
    }

    public function testItGivesTheColourInputAFixedHeightSoTheSwatchDoesNotCollapse(): void
    {
        $this->assertMatchesRegularExpression('/(^|;|\s)height:\s*var\(--ffb-control-h\)/', $this->rule('.ffb-skin .ffb-color-picker'));
        $this->assertMatchesRegularExpression('/(^|;|\s)height:\s*2.75em/', $this->rule('.ffb .ffb-color-picker'));
        $this->assertStringContainsString('flex: 0 1 calc(9ch + 2em)', $this->declarations('.ffb .ffb-color-hex'));
        $this->assertStringContainsString('repeating-linear-gradient', $this->declarations('.ffb .ffb-color[data-ffb-empty] .ffb-color-picker::-webkit-color-swatch'));
    }

    public function testItClearsThemeFieldsetMarginsSoGroupedFieldsUseTheGridGap(): void
    {
        $this->assertMatchesRegularExpression('/(^|;|\s)margin:\s*0;/', $this->rule('.ffb fieldset.ffb-field'));
    }

    public function testItSuppressesThemeSwitchThumbsSoTheToggleDrawsOneKnob(): void
    {
        $this->assertMatchesRegularExpression('/content:\s*none/', $this->rule('.ffb-skin input.ffb-choice.ffb-switch[type="checkbox"]::after'));
    }

    public function testItHidesTheNewTabCueVisuallyWithAndWithoutTheSkin(): void
    {
        $this->assertMatchesRegularExpression('/clip:\s*rect\(0, 0, 0, 0\)/', $this->declarations('.ffb .ffb-sr-only'));
    }

    public function testItStepsHeadingSizesDownFromPageTitleToFieldLabel(): void
    {
        $sizes = array_map($this->emSize(...), [
            '.ffb-skin .ffb-page-title',
            '.ffb-skin h2.ffb-heading',
            '.ffb-skin .ffb-section > .ffb-section-title',
            '.ffb-skin h3.ffb-heading',
            '.ffb-skin h4.ffb-heading',
            '.ffb-skin .ffb-label',
        ]);
        foreach (array_slice($sizes, 1) as $i => $size) {
            $this->assertGreaterThanOrEqual(0.125, $sizes[$i] - $size, 'step ' . ($i + 1));
        }
        $this->assertSame($this->emSize('.ffb-skin h3.ffb-heading'), $this->emSize('.ffb-skin .ffb-rich h3'));
        $this->assertSame($this->emSize('.ffb-skin h4.ffb-heading'), $this->emSize('.ffb-skin .ffb-rich h4'));
    }

    public function testItNumbersRichTextListsWithAndWithoutTheSkin(): void
    {
        $this->assertStringContainsString('list-style: decimal', $this->declarations('.ffb-skin .ffb-rich ol'));
        $this->assertStringContainsString('list-style: decimal', $this->declarations(':where(.ffb:not(.ffb-skin)) .ffb-rich ol'));
        $this->assertStringContainsString('list-style: disc', $this->declarations(':where(.ffb:not(.ffb-skin)) .ffb-rich ul'));
    }

    public function testItGivesUnskinnedButtonsAVisibleBorder(): void
    {
        $this->assertMatchesRegularExpression('/border:\s*1px solid currentColor/', $this->declarations(':where(.ffb:not(.ffb-skin)) .ffb-btn'));
    }

    public function testItDropsThemeInvalidOutlinesOnSkinnedControls(): void
    {
        $this->assertMatchesRegularExpression(
            '/outline:\s*none/',
            $this->declarations('.ffb-skin :is(.ffb-input, input.ffb-choice):is([aria-invalid="true"], :user-invalid)')
        );
    }

    public function testItLaysTheAddressOutInFixedColumnsThatCollapseWithTheField(): void
    {
        $this->assertStringContainsString('repeat(3, minmax(0, 1fr))', $this->declarations('.ffb .ffb-address'));
        $this->assertStringContainsString('inline-size', $this->declarations('.ffb .ffb-type-address'));
        $this->assertStringContainsString('minmax(0, 1fr)', $this->declarations('.ffb .ffb-address', '@container ffb-address'));
    }

    public function testItLetsCardsFillTheRow(): void
    {
        $this->assertStringContainsString('repeat(auto-fit,', $this->declarations('.ffb .ffb-cards'));
    }

    public function testItKeepsTheSummaryEditButtonBesideTheValueOnNarrowScreens(): void
    {
        $this->assertMatchesRegularExpression('/grid-column:\s*2/', $this->declarations('.ffb .ffb-summary-row .ffb-summary-action', '479px'));
        $this->assertMatchesRegularExpression('/grid-column:\s*1/', $this->declarations('.ffb .ffb-summary-row dd:not(.ffb-summary-action)', '479px'));
        $this->assertStringContainsString('min-height: 24px', $this->declarations('.ffb .ffb-summary-row .ffb-summary-edit'));
        $this->assertStringContainsString('white-space: pre-line', $this->declarations('.ffb .ffb-summary-row dd'));
    }

    public function testItMarksCompletedStepsWithMoreThanColour(): void
    {
        $this->assertStringContainsString('\\2713', $this->declarations('.ffb .ffb-progress-step[data-ffb-done]::before'));
        $this->assertStringContainsString('4px', $this->declarations('.ffb .ffb-progress-step[data-ffb-done]'));
        $this->assertStringNotContainsString(':has(~ [aria-current', (string)file_get_contents(self::CSS));
        $this->assertStringContainsString('2px', $this->declarations('.ffb .ffb-progress-step'));
        $this->assertStringContainsString('var(--ffb-border-strong)', $this->declarations('.ffb-skin .ffb-progress-step'));
    }

    public function testItKeepsOnlyTheCurrentStepLabelOnNarrowScreens(): void
    {
        $this->assertStringContainsString('height: 0', $this->declarations('.ffb .ffb-progress-step:not([aria-current="step"])', '479px'));
        $this->assertStringContainsString('white-space: normal', $this->declarations('.ffb .ffb-progress-step[aria-current="step"]', '479px'));
    }

    public function testItGivesUnskinnedHyvaHeadingsTheSkinScaleWithClassSpecificity(): void
    {
        $pairs = [
            '.ffb-skin .ffb-page-title' => '.ffb:not(.ffb-skin) .ffb-page-title',
            '.ffb-skin h2.ffb-heading' => '.ffb:not(.ffb-skin) h2.ffb-heading',
            '.ffb-skin .ffb-section > .ffb-section-title' => '.ffb:not(.ffb-skin) .ffb-section-title',
            '.ffb-skin h3.ffb-heading' => '.ffb:not(.ffb-skin) .ffb-summary-title',
            '.ffb-skin .ffb-rich h4' => '.ffb:not(.ffb-skin) .ffb-rich h4',
        ];
        foreach ($pairs as $skin => $theme) {
            $this->assertSame($this->emSize($skin), $this->emSize($theme, self::HYVA_CSS), $theme);
        }
        $hyva = (string)file_get_contents(self::HYVA_CSS);
        $this->assertStringNotContainsString(':where(', $hyva);
        $this->assertStringNotContainsString(':where(.ffb:not(.ffb-skin) .ffb-page-title', (string)file_get_contents(self::CSS));
        $this->assertStringContainsString('font-size: inherit', $this->declarations('.ffb:not(.ffb-skin) legend.ffb-label', '', self::HYVA_CSS));
        $this->assertFileDoesNotExist(__DIR__ . '/../../../view/frontend/layout/hyva_default.xml', 'form.phtml links it only where needed');
        $template = (string)file_get_contents(__DIR__ . '/../../../view/frontend/templates/form.phtml');
        $this->assertStringContainsString("<?php if (!\$skinned && \$form->shouldRenderHyvaStyles()): ?>", $template);
        $this->assertStringContainsString("Furan_FormBuilder::css/ffb-hyva.css", $template);
    }

    public function testItStartsTheNarrowCurrentStepLabelAtTheListStart(): void
    {
        $current = $this->declarations('.ffb .ffb-progress-step[aria-current="step"]', '479px');
        $this->assertStringContainsString('flex: 1 0 100%', $current);
        $this->assertStringContainsString('order: 3', $current);
        $standIn = $this->declarations('.ffb.ffb-js .ffb-progress::before', '479px');
        $this->assertStringContainsString('order: 1', $standIn);
        $this->assertStringContainsString('flex: 0 0 2.5em', $standIn);
        $this->assertStringContainsString('var(--ffb-accent)', $this->declarations('.ffb-skin.ffb-js .ffb-progress::before'));
        $this->assertStringContainsString('order: 2', $this->declarations('.ffb .ffb-progress-step[aria-current="step"] ~ .ffb-progress-step', '479px'));
    }

    public function testItSizesTheSummaryTitleLikeAContentH3(): void
    {
        $this->assertSame($this->emSize('.ffb-skin h3.ffb-heading'), $this->emSize('.ffb-skin .ffb-summary-title'));
    }

    public function testItSetsUnskinnedPrimaryButtonsAndNoticesApart(): void
    {
        $this->assertStringContainsString('border-width: 2px', $this->declarations(':where(.ffb:not(.ffb-skin)) .ffb-btn:where(:not(.ffb-btn-secondary))'));
        $this->assertStringContainsString('border-left: 4px solid currentColor', $this->declarations(':where(.ffb:not(.ffb-skin)) .ffb-notice'));
    }

    public function testItKeepsLayoutOutOfSkinOnlyRules(): void
    {
        $css = (string)preg_replace('~/\*.*?\*/~s', '', (string)file_get_contents(self::CSS));
        preg_match_all('/([^{}]*)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        $layout = '/(^|;)\s*(grid-template-columns|grid-column|grid-row|flex-direction|flex-wrap|justify-content|order|white-space)\s*:/';
        $skinOnly = [];
        foreach ($rules as [, $head, $body]) {
            if (str_contains($head, '.ffb-skin') && !str_contains($head, ':not(.ffb-skin)') && preg_match($layout, $body) === 1) {
                $skinOnly[] = trim($head);
            }
        }
        $this->assertSame([], $skinOnly);
    }
}
