<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\View;

use DOMDocument;
use DOMXPath;
use Furan\FormBuilder\Block\Account\Submission;
use Furan\FormBuilder\Block\Account\Submissions;
use Furan\FormBuilder\Block\Thread\View;
use Furan\FormBuilder\Model\Content\MiniMarkdown;
use Furan\FormBuilder\Model\Thread\MessageRenderer;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use Magento\Framework\View\Element\Template;
use PHPUnit\Framework\TestCase;

class AccountStylesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../view/frontend';

    private function include(string $template, object $block): string
    {
        $escaper = EscaperFactory::create();
        $render = static function () use ($template, $block, $escaper): string {
            ob_start();
            include self::ROOT . '/templates/' . $template;
            return (string)ob_get_clean();
        };
        return $render();
    }

    private function mockBlock(string $class): object
    {
        $renderer = new MessageRenderer(EscaperFactory::create(), new MiniMarkdown());
        $block = $this->createStub($class);
        $block->method('getViewFileUrl')->willReturnCallback(static fn (string $f): string => 'https://shop.test/static/' . $f);
        $block->method('formatDate')->willReturnArgument(0);
        $block->method('getBlockHtml')->willReturn('<input name="form_key" type="hidden" value="FK">');
        if (method_exists($class, 'renderMessage')) {
            $block->method('renderMessage')->willReturnCallback(
                static fn (array $m): string => $renderer->html((string)$m['author'], (string)$m['body'])
            );
        }
        foreach (['getReplyUrl', 'getBackUrl', 'getLatestUrl', 'getEditUrl', 'getDeleteUrl', 'getExportUrl', 'getDeleteAllUrl'] as $m) {
            if (method_exists($class, $m)) {
                $block->method($m)->willReturn('https://shop.test/furanform/x');
            }
        }
        return $block;
    }

    private function view(): array
    {
        return [
            'id' => 9, 'title' => 'Contact', 'created_at' => '2026-11-01', 'status' => 2, 'status_label' => 'Answered',
            'reference' => '0192abcd-0000-7000-8000-000000000001', 'closed' => false, 'can_reply' => true,
            'reply_state' => 'open', 'can_edit' => true, 'edit_closed' => false, 'can_delete' => true, 'one_response' => false,
            'earlier' => false, 'before' => null, 'groups' => [],
            'thread' => [['message_id' => 51, 'author' => 'admin', 'author_name' => 'Shop', 'body' => 'Hi', 'created_at' => 'd1']],
        ];
    }

    private function submissionsHtml(?array $rows = null): string
    {
        $rows ??= [[
            'submission_id' => 9, 'created_at' => '2026-11-01', 'form_title' => 'A "quoted" <form>', 'reference' => 'ref-1',
            'summary' => 'Hello', 'status' => 1,
        ]];
        $block = $this->mockBlock(Submissions::class);
        $block->method('getPage')->willReturn(['rows' => $rows, 'older' => null]);
        $block->method('getOlderUrl')->willReturn(null);
        $block->method('getViewUrl')->willReturn('https://shop.test/furanform/account/submission/id/9/');
        $block->method('hasOneResponseAnswers')->willReturn(false);
        $block->method('getStatusLabel')->willReturn('In progress');
        return $this->include('account/submissions.phtml', $block);
    }

    private function submissionHtml(): string
    {
        $block = $this->mockBlock(Submission::class);
        $block->method('getView')->willReturn($this->view());
        return $this->include('account/submission.phtml', $block);
    }

    private function threadHtml(bool $available = true): string
    {
        $block = $this->mockBlock(View::class);
        $block->method('getView')->willReturn($available ? $this->view() : null);
        return $this->include('thread/view.phtml', $block);
    }

    /** @return array<string, string> */
    private function pages(): array
    {
        return [
            'my submissions' => $this->submissionsHtml(),
            'my submissions (empty)' => $this->submissionsHtml([]),
            'submission view' => $this->submissionHtml(),
            'guest reply' => $this->threadHtml(),
            'guest reply (unavailable)' => $this->threadHtml(false),
        ];
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        libxml_clear_errors();
        return new DOMXPath($dom);
    }

    private static function hasClass(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }

    public function testItLoadsTheAccountStylesheetOnMySubmissionsTheSubmissionViewAndTheGuestReplyPageOnly(): void
    {
        foreach ($this->pages() as $name => $html) {
            $this->assertSame(
                1,
                substr_count($html, '<link rel="stylesheet" href="https://shop.test/static/Furan_FormBuilder::css/account.css">'),
                $name
            );
        }

        $using = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (in_array($file->getExtension(), ['phtml', 'xml'], true)
                && str_contains((string)file_get_contents($file->getPathname()), 'css/account.css')
            ) {
                $using[] = substr($file->getPathname(), strlen(self::ROOT) + 1);
            }
        }
        sort($using);
        $this->assertSame(
            ['templates/account/submission.phtml', 'templates/account/submissions.phtml', 'templates/thread/view.phtml'],
            $using
        );
    }

    public function testItWrapsEachAccountPageInTheFfbAccountRoot(): void
    {
        foreach ($this->pages() as $name => $html) {
            $xpath = $this->xpath($html);
            $roots = $xpath->query('//*[' . self::hasClass('ffb-account') . ']');
            $this->assertSame(1, $roots->length, $name);
            $stray = $xpath->query(
                '//*[contains(@class, "ffb-account-") or ' . self::hasClass('ffb-thread') . ']'
                . '[not(ancestor-or-self::*[' . self::hasClass('ffb-account') . '])]'
            );
            $this->assertSame(0, $stray->length, $name . ': every ffb element sits inside the root');
        }
        $guest = $this->xpath($this->threadHtml());
        $this->assertSame(1, $guest->query('//*[' . self::hasClass('ffb-account') . ' and ' . self::hasClass('ffb-thread') . ']')->length);
        $mine = $this->xpath($this->submissionHtml());
        $this->assertSame(1, $mine->query('//*[' . self::hasClass('ffb-account') . ' and ' . self::hasClass('ffb-account-submission') . ']')->length);
    }

    public function testItKeepsTheLumaClassesOnAccountButtonsAndTables(): void
    {
        $list = $this->xpath($this->submissionsHtml());
        $this->assertSame(1, $list->query('//table[' . self::hasClass('data') . ' and ' . self::hasClass('table') . ']')->length);
        $this->assertSame(2, $list->query('//button[' . self::hasClass('action') . ']')->length);

        $view = $this->xpath($this->submissionHtml());
        $this->assertSame(1, $view->query('//button[@type="submit"][' . self::hasClass('action') . ' and ' . self::hasClass('primary') . ']')->length);
        $this->assertSame(1, $view->query('//a[' . self::hasClass('action') . ' and ' . self::hasClass('edit') . ']')->length);
        $this->assertSame(1, $view->query('//button[' . self::hasClass('action') . ' and ' . self::hasClass('delete') . ']')->length);

        $guest = $this->xpath($this->threadHtml());
        $this->assertSame(1, $guest->query('//button[' . self::hasClass('action') . ' and ' . self::hasClass('primary') . ']')->length);
    }

    public function testItLabelsEveryTableCellForTheStackedMobileLayout(): void
    {
        $html = $this->submissionsHtml();
        $xpath = $this->xpath($html);
        $heads = [];
        foreach ($xpath->query('//thead/tr/th') as $th) {
            $heads[] = trim($th->textContent);
        }
        $this->assertContains('Reference', $heads);

        $cells = $xpath->query('//tbody/tr[1]/td');
        $this->assertSame(count($heads), $cells->length);
        foreach ($cells as $i => $td) {
            $this->assertSame($heads[$i], $td->attributes->getNamedItem('data-th')->nodeValue, 'cell ' . $i);
        }
        $this->assertSame('A "quoted" <form>', $cells->item(1)->textContent);
        $this->assertStringNotContainsString('data-th="A "', $html);
    }

    public function testItScopesEveryAccountStyleSelectorUnderTheFfbAccountOrThreadRoot(): void
    {
        $seen = 0;
        foreach (self::cssRules((string)file_get_contents(self::ROOT . '/web/css/account.css')) as [$selectors]) {
            foreach (array_map('trim', explode(',', $selectors)) as $selector) {
                $seen++;
                $this->assertMatchesRegularExpression('/^\.(ffb-account|ffb-thread)(?![\w-])/', $selector, $selector);
                $this->assertStringNotContainsString('!important', $selector);
            }
        }
        $this->assertGreaterThan(20, $seen);

        $css = (string)file_get_contents(self::ROOT . '/web/css/account.css');
        $this->assertStringNotContainsString('!important', $css);
        foreach (self::cssRules($css) as [$selectors]) {
            foreach (array_map('trim', explode(',', $selectors)) as $selector) {
                if (preg_match('/^\.ffb-(?:account|thread)(?![\w-])\s*$/', $selector)) {
                    continue; // the roots themselves
                }
                $this->assertGreaterThanOrEqual(2, preg_match_all('/\.[a-z]/i', $selector), $selector . ' needs two classes');
            }
        }
    }

    public function testItDeclaresTheFfbCustomPropertiesOnTheAccountRoots(): void
    {
        $ffb = (string)file_get_contents(self::ROOT . '/web/css/ffb.css');
        $account = (string)file_get_contents(self::ROOT . '/web/css/account.css');
        $form = self::declarations($ffb, '.ffb');
        $roots = self::declarations($account, '.ffb-account, .ffb-thread');

        // The --ffb-fs-* type scale sizes form headings only; account pages keep their own title sizes.
        $names = array_filter(
            array_keys($form),
            static fn (string $n): bool => str_starts_with($n, '--ffb-') && !str_starts_with($n, '--ffb-fs-')
        );
        $this->assertNotEmpty($names);
        foreach ($names as $name) {
            $this->assertSame($form[$name], $roots[$name] ?? null, $name);
        }
        $this->assertStringContainsString('color-mix', $account);
    }

    public function testItUsesNoInlineStyleAttributesOrStyleBlocksInAccountTemplates(): void
    {
        foreach (['account/submissions.phtml', 'account/submission.phtml', 'thread/view.phtml', 'form_closed.phtml'] as $file) {
            $source = (string)file_get_contents(self::ROOT . '/templates/' . $file);
            $this->assertDoesNotMatchRegularExpression('/<style|\sstyle\s*=/i', $source, $file);
        }
        foreach ($this->pages() as $name => $html) {
            $this->assertDoesNotMatchRegularExpression('/<style|\sstyle\s*=/i', $html, $name);
        }
    }

    public function testItLaysOutTheNewsletterCheckboxLikeTheConsentCheckbox(): void
    {
        $css = (string)file_get_contents(self::ROOT . '/web/css/ffb.css');
        $this->assertStringContainsString(':not(.ffb-type-newsletter) > .ffb-label', $css);
        foreach (['.ffb .ffb-type-newsletter {', '.ffb .ffb-type-newsletter > .ffb-label {', '.ffb .ffb-type-newsletter > :not(.ffb-choice):not(.ffb-label) {'] as $selector) {
            $this->assertStringContainsString($selector, $css);
        }
        $this->assertMatchesRegularExpression('/\.ffb \.ffb-type-toggle,\s*\.ffb \.ffb-type-newsletter \{/', $css);
    }

    public function testItStylesDeleteAllAsADestructiveAction(): void
    {
        $xpath = $this->xpath($this->submissionsHtml());
        $button = $xpath->query('//form[contains(@class,"ffb-account-delete-all")]//button')->item(0);
        $this->assertNotNull($button);
        $this->assertSame('action delete', $button->attributes->getNamedItem('class')->nodeValue);
    }

    public function testItClearsTheThemeBorderTopOnTableCells(): void
    {
        $css = (string)file_get_contents(self::ROOT . '/web/css/account.css');
        $found = 0;
        foreach (self::cssRules($css) as [$selector, $body]) {
            if (preg_match('/table td$/', $selector)) {
                $this->assertSame('0', self::parseDeclarations($body)['border-top'] ?? null, $selector);
                $found++;
            }
        }
        $this->assertSame(2, $found);
    }

    public function testItShowsADashForAnEmptyReferenceAndNoRepeatedViewLabel(): void
    {
        $xpath = $this->xpath($this->submissionsHtml([[
            'submission_id' => 9, 'created_at' => '2026-11-01', 'form_title' => 'F', 'reference' => '',
            'summary' => 'Hello', 'status' => 1,
        ]]));
        $this->assertSame("\u{2014}", trim($xpath->query('//tbody/tr[1]/td[3]')->item(0)->textContent));
        $view = $xpath->query('//tbody/tr[1]/td[6]')->item(0);
        $this->assertStringContainsString('ffb-account-cell-view', $view->attributes->getNamedItem('class')->nodeValue);
        $css = (string)file_get_contents(self::ROOT . '/web/css/account.css');
        $this->assertMatchesRegularExpression('/ffb-account-cell-view::before \{ display: none/', $css);
    }

    public function testItDoesNotAnnounceTheServerRenderedClosedBox(): void
    {
        $block = $this->createStub(Template::class);
        $block->method('getData')->willReturn('Closed');
        $block->method('getViewFileUrl')->willReturn('x');
        $this->assertStringNotContainsString('role=', $this->include('form_closed.phtml', $block));
    }

    public function testItStylesTheClosedFormBoxEvenWhenNoFormLoadedTheStylesheet(): void
    {
        $block = $this->createStub(Template::class);
        $block->method('getData')->willReturn('Closed <b>now</b>');
        $block->method('getViewFileUrl')->willReturnCallback(static fn (string $f): string => 'https://shop.test/static/' . $f);
        $html = $this->include('form_closed.phtml', $block);

        $this->assertStringContainsString('<link rel="stylesheet" href="https://shop.test/static/Furan_FormBuilder::css/ffb.css">', $html);
        $this->assertStringContainsString('class="ffb ffb-closed"', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function testItKeepsEveryAccountColourPairAtWcagAaContrast(): void
    {
        $css = (string)file_get_contents(self::ROOT . '/web/css/account.css');
        $vars = self::declarations($css, '.ffb-account, .ffb-thread');
        $resolve = static function (string $value) use ($vars): ?string {
            if (preg_match('/^var\((--[\w-]+)\)$/', $value, $m)) {
                $value = $vars[$m[1]] ?? '';
            }
            return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value) ? $value : null;
        };
        $checked = 0;
        foreach (self::cssRules($css) as [$selectors, $body]) {
            $props = self::parseDeclarations($body);
            $fg = isset($props['color']) ? $resolve($props['color']) : null;
            if ($fg === null) {
                continue;
            }
            $bgRaw = $props['background-color'] ?? $props['background'] ?? $vars['--ffb-surface'];
            $bg = $resolve($bgRaw);
            if ($bg === null) {
                continue;
            }
            $checked++;
            $this->assertGreaterThanOrEqual(4.5, self::contrast($fg, $bg), "{$selectors}: {$fg} on {$bg}");
        }
        $this->assertGreaterThan(8, $checked);
        $this->assertStringNotContainsString('#666', $css);
    }

    public function testItRespectsReducedMotionAndKeepsTargetsAtLeast44Pixels(): void
    {
        $css = (string)file_get_contents(self::ROOT . '/web/css/account.css');
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\) \{[^@]*transition: none;/', $css);
        $this->assertMatchesRegularExpression('/min-height: 44px/', $css);
        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringContainsString('content: attr(data-th)', $css);
    }

    /** @return list<array{0: string, 1: string}> selector list and body of every style rule (inside @media/@supports too) */
    private static function cssRules(string $css): array
    {
        $css = (string)preg_replace('~/\*.*?\*/~s', '', $css);
        $rules = [];
        $walk = static function (string $text) use (&$walk, &$rules): void {
            $pos = 0;
            $len = strlen($text);
            while (($open = strpos($text, '{', $pos)) !== false) {
                $depth = 1;
                $i = $open + 1;
                while ($i < $len && $depth > 0) {
                    $depth += $text[$i] === '{' ? 1 : ($text[$i] === '}' ? -1 : 0);
                    $i++;
                }
                $prelude = trim(substr($text, $pos, $open - $pos));
                $body = substr($text, $open + 1, $i - $open - 2);
                if (str_starts_with($prelude, '@')) {
                    $walk($body);
                } else {
                    $rules[] = [$prelude, $body];
                }
                $pos = $i;
            }
        };
        $walk($css);
        return $rules;
    }

    /** @return array<string, string> */
    private static function parseDeclarations(string $body): array
    {
        $out = [];
        foreach (explode(';', $body) as $declaration) {
            if (str_contains($declaration, ':')) {
                [$name, $value] = explode(':', $declaration, 2);
                $out[trim($name)] = trim($value);
            }
        }
        return $out;
    }

    /** @return array<string, string> declarations of the first top-level rule with exactly this selector list */
    private static function declarations(string $css, string $selector): array
    {
        foreach (self::cssRules($css) as [$selectors, $body]) {
            if (preg_replace('/\s+/', ' ', $selectors) === $selector) {
                return self::parseDeclarations($body);
            }
        }
        return [];
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $channels = array_map(static function (string $pair): float {
            $c = hexdec($pair) / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split($hex, 2));
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }
}
