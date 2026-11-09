<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Content;

use Furan\FormBuilder\Model\Content\MiniMarkdown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MiniMarkdownTest extends TestCase
{
    private MiniMarkdown $markdown;

    protected function setUp(): void
    {
        $this->markdown = new MiniMarkdown();
    }

    private function render(string $text): string
    {
        return $this->markdown->render($text);
    }

    public function testItRendersEmptyTextAsNothing(): void
    {
        $this->assertSame('', $this->render("  \n\n "));
    }

    public function testItSplitsParagraphsOnBlankLinesAndBreaksLines(): void
    {
        $this->assertSame("<p>One<br>\ntwo</p>\n<p>Three</p>", $this->render("One\r\ntwo\n\n\n\nThree"));
    }

    public function testItRendersBoldAndItalic(): void
    {
        $this->assertSame(
            '<p>a <strong>b</strong> and <em>c</em> and <strong><em>d</em></strong></p>',
            $this->render('a **b** and *c* and ***d***')
        );
    }

    public function testItLeavesUnbalancedMarkersAsText(): void
    {
        $this->assertSame('<p>2 * 3 ** 4 *x</p>', $this->render('2 * 3 ** 4 *x'));
    }

    public function testItRendersBulletLists(): void
    {
        $this->assertSame(
            "<p>Intro</p>\n<ul><li>one <strong>b</strong></li><li>two</li></ul>\n<p>After</p>",
            $this->render("Intro\n\n- one **b**\n- two\n\nAfter")
        );
    }

    public function testItEndsAListWhenParagraphTextFollowsWithoutBlankLine(): void
    {
        $this->assertSame(
            "<ul><li>one</li></ul>\n<p>text</p>",
            $this->render("- one\ntext")
        );
    }

    public function testItRendersHttpsLinksWithNoopenerAndBlankTarget(): void
    {
        $this->assertSame(
            '<p><a href="https://example.com/a?x=1&amp;y=2" rel="noopener noreferrer" target="_blank">go</a></p>',
            $this->render('[go](https://example.com/a?x=1&y=2)')
        );
    }

    public function testItRendersRelativeAndMailtoLinksWithoutTarget(): void
    {
        $this->assertSame('<p><a href="/privacy?a=1">p</a></p>', $this->render('[p](/privacy?a=1)'));
        $this->assertSame('<p><a href="mailto:a@b.com">m</a></p>', $this->render('[m](mailto:a@b.com)'));
    }

    public function testItRendersFormattingInsideLinkLabels(): void
    {
        $this->assertSame(
            '<p><a href="/x"><strong>a</strong></a> <strong><a href="/y">b</a></strong></p>',
            $this->render('[**a**](/x) **[b](/y)**')
        );
    }

    #[DataProvider('unsafeLinks')]
    public function testItRendersUnsafeLinkTargetsAsPlainText(string $input): void
    {
        $html = $this->render($input);
        $this->assertStringNotContainsString('<a ', $html, $input);
        $this->assertStringNotContainsString('href', $html, $input);
    }

    public static function unsafeLinks(): array
    {
        return [
            ['[x](javascript:alert(1))'],
            ['[x](javascript:alert)'],
            ['[x](JaVaScRiPt:alert)'],
            ['[x](  javascript:alert)'],
            ['[x](&#106;avascript:alert)'],
            ['[x](java&#x09;script:alert)'],
            ['[x](data:text/html;base64,AAAA)'],
            ['[x](vbscript:x)'],
            ['[x](//evil.com)'],
            ['[x](/\evil.com)'],
            ['[x](\\evil.com)'],
            ['[x](evil.com)'],
            ['[x](mailto:not-an-email)'],
            ['[x](mailto:a@b.com?cc=c@d.com)'],
            ['[x](http://)'],
            ['[x](https:evil.com)'],
            ['[x]()'],
        ];
    }

    public function testItEscapesHtmlAndCannotBreakOutOfTheHrefAttribute(): void
    {
        $html = $this->render('<script>alert(1)</script> [a](https://e.com/"onmouseover="alert(1)) [b](/x"y) [c](/x\'y)');
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onmouseover="', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);

        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        foreach ($doc->getElementsByTagName('a') as $a) {
            $this->assertSame(['href', 'rel', 'target'], array_map(fn ($n) => $n->nodeName, iterator_to_array($a->attributes)));
        }
    }

    public function testItDoesNotApplyEmphasisInsideUrls(): void
    {
        $html = $this->render('[a](/x*y*z) **b**');
        $this->assertStringContainsString('href="/x*y*z"', $html);
        $this->assertStringNotContainsString('<em>', $html);
    }

    public function testItNeutralisesEntityAndMarkupTricksInText(): void
    {
        $html = $this->render('&lt;script&gt; &#60;b&#62; <img src=x onerror=alert(1)> **<i>x</i>**');
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringContainsString('&amp;lt;script&amp;gt;', $html);
        $this->assertStringContainsString('&amp;#60;b&amp;#62;', $html);
    }

    public function testItIgnoresNullBytesInInputSoPlaceholdersCannotBeForged(): void
    {
        $html = $this->render("\0L0\0 [a](/x)");
        $this->assertSame(1, substr_count($html, '<a '));
        $this->assertStringNotContainsString("\0", $html);
    }

    #[DataProvider('messageFixtures')]
    public function testItRendersMessagesLikeTheSharedFixtures(string $text, string $html): void
    {
        $this->assertSame($html, $this->markdown->renderMessage($text));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function messageFixtures(): array
    {
        $cases = [];
        foreach (json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/reply-markdown.json'), true) as $case) {
            $cases[$case['name']] = [$case['text'], $case['html']];
        }
        return $cases;
    }

    public function testItGivesEveryMessageLinkOnlyTheFixedAttributes(): void
    {
        $html = $this->markdown->renderMessage('[a](https://e.com/x) [b](mailto:a@b.co) [c](/rel) [d](https://e.com/"onclick="x)');
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        $anchors = iterator_to_array($doc->getElementsByTagName('a'));
        $this->assertCount(2, $anchors);
        foreach ($anchors as $a) {
            $this->assertSame('noopener noreferrer nofollow', $a->getAttribute('rel'));
            $this->assertMatchesRegularExpression('~^(https://|mailto:)~', $a->getAttribute('href'));
            $this->assertFalse($a->hasAttribute('onclick'));
        }
    }

    public function testItLeavesRichTextRenderingUnchanged(): void
    {
        $this->assertSame('<p><a href="/privacy">p</a></p>', $this->render('[p](/privacy)'));
        $this->assertSame('<p>1. one</p>', $this->render('1. one'));
    }
}
