<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Thread;

use Furan\FormBuilder\Block\Thread\View;
use Furan\FormBuilder\Model\Content\MiniMarkdown;
use Furan\FormBuilder\Model\Thread\MessageRenderer;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use PHPUnit\Framework\TestCase;

class ViewTemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../../view/frontend/templates/thread/view.phtml';

    private function render(?array $view): string
    {
        $renderer = new MessageRenderer(EscaperFactory::create(), new MiniMarkdown());
        $block = $this->createStub(View::class);
        $block->method('getView')->willReturn($view);
        $block->method('getViewFileUrl')->willReturn('https://shop.test/static/account.css');
        $block->method('formatDate')->willReturnArgument(0);
        $block->method('getReplyUrl')->willReturn('https://shop.test/furanform/thread/reply?id=42');
        $block->method('getEarlierUrl')->willReturnCallback(static fn (int $b): string => 'https://shop.test/furanform/thread/view?id=42&before=' . $b);
        $block->method('getLatestUrl')->willReturn('https://shop.test/furanform/thread/view?id=42');
        $block->method('getBlockHtml')->willReturn('<input name="form_key" type="hidden" value="FK">');
        $block->method('renderMessage')->willReturnCallback(
            static fn (array $m): string => $renderer->html((string)$m['author'], (string)$m['body'])
        );
        $escaper = EscaperFactory::create();
        $render = static function () use ($block, $escaper): string {
            ob_start();
            include self::TEMPLATE;
            return (string)ob_get_clean();
        };
        return $render();
    }

    private function view(array $overrides = []): array
    {
        return $overrides + [
            'id' => 42, 'title' => '<b>Contact</b>', 'created_at' => '2026-11-01', 'status' => 2,
            'status_label' => 'Answered', 'closed' => false, 'can_reply' => true, 'reply_state' => 'open',
            'earlier' => false, 'before' => null,
            'thread' => [
                ['message_id' => 301, 'author' => 'customer', 'author_name' => 'You', 'body' => "<script>x</script> **raw**\nline", 'created_at' => 'd1'],
                ['message_id' => 302, 'author' => 'admin', 'author_name' => '<i>Acme</i>', 'body' => '**Bold** [x](javascript:alert(1))', 'created_at' => 'd2'],
            ],
        ];
    }

    public function testItRendersTheConversationSafelyWithAKeyedReplyForm(): void
    {
        $html = $this->render($this->view());

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>Contact', $html);
        $this->assertStringNotContainsString('<i>Acme', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt; **raw**<br', $html);
        $this->assertStringContainsString('<strong>Bold</strong>', $html);
        $this->assertStringContainsString('action="https://shop.test/furanform/thread/reply?id=42"', $html);
        $this->assertStringContainsString('name="form_key"', $html);
        $this->assertStringContainsString('data-ffb-max="5000"', $html);
    }

    public function testItEnhancesTheReplyBoxWithACounterAndAQuietLiveRegion(): void
    {
        $html = $this->render($this->view());

        $this->assertMatchesRegularExpression('/<form class="ffb-account-reply"[^>]*data-ffb-reply>/', $html);
        $this->assertMatchesRegularExpression('/<textarea [^>]*data-ffb-max="5000"[^>]*data-ffb-reply-body/', $html);
        $this->assertStringContainsString('data-ffb-reply-count', $html);
        $this->assertStringContainsString('%1 characters left', html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
        $this->assertMatchesRegularExpression('/data-ffb-reply-live aria-live="polite"/', $html);
        $this->assertStringContainsString('<script src="https://shop.test/static/account.css" defer></script>', $html);
        $this->assertStringNotContainsString('data-ffb-md', $html, 'no formatting toolbar for customers');
    }

    public function testItShowsTheThreadReadOnlyWhenRepliesAreOff(): void
    {
        $html = $this->render($this->view(['can_reply' => false, 'reply_state' => 'off']));

        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('Replies are closed for this form.', $html);
    }

    public function testItReplacesTheReplyBoxWithTheLimitMessageWhenTheThreadIsFull(): void
    {
        $html = $this->render($this->view(['can_reply' => false, 'reply_state' => 'full']));

        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringContainsString('reached its message limit', $html);
    }

    public function testItLinksToEarlierMessagesFromTheOldestShownMessage(): void
    {
        $html = $this->render($this->view(['earlier' => true]));

        $this->assertStringContainsString('Showing 2 messages. Earlier messages are hidden.', $html);
        $this->assertStringContainsString('href="https://shop.test/furanform/thread/view?id=42&amp;before=301"', $html);
        $this->assertStringNotContainsString('Show latest messages', $html);

        $html = $this->render($this->view(['before' => 400]));
        $this->assertStringNotContainsString('Show earlier messages', $html);
        $this->assertStringContainsString('Show latest messages', $html);
    }

    public function testItIsReadOnlyForClosedConversations(): void
    {
        $html = $this->render($this->view(['closed' => true, 'can_reply' => false, 'reply_state' => 'closed']));

        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringContainsString('This conversation is closed.', $html);
    }

    public function testItRendersOneGenericBodyWithoutAnyThreadData(): void
    {
        $html = $this->render(null);

        $this->assertStringContainsString('This link is not available', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertSame($html, $this->render(null));
    }

    public function testItLoadsNoThirdPartyResourcesOrScripts(): void
    {
        $this->assertStringNotContainsString('<script', $this->render(null));
        foreach ([$this->render($this->view()), $this->render(null)] as $html) {
            // Only first-party script files, never inline code.
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=")|<script[^>]*>[^<]/', $html);
            preg_match_all('/(?:src|href)="([^"]+)"/', $html, $urls);
            foreach ($urls[1] as $url) {
                $this->assertStringStartsWith('https://shop.test/', $url);
            }
        }
    }

    public function testItUsesTheFullContentColumnLeftAlignedWithThePageTitle(): void
    {
        $css = (string)file_get_contents(__DIR__ . '/../../../../view/frontend/web/css/account.css');
        preg_match('/^\.ffb-thread \{([^}]*)\}/m', $css, $rule);

        $this->assertNotEmpty($rule, 'a base .ffb-thread rule');
        $this->assertStringContainsString('max-width: 760px', $rule[1]);
        $this->assertStringNotContainsString('auto', $rule[1], 'not centred away from the page title');
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 768px\) \{[^@]*\.ffb-thread \.ffb-account-message \{[^}]*max-width: none;[^}]*margin-left: 0;/s',
            $css
        );
        $this->assertMatchesRegularExpression('/\.ffb-thread \.ffb-account-reply \{[^}]*max-width: none;/', $css);
    }

    public function testItIsNeverFullPageCached(): void
    {
        $layout = simplexml_load_file(__DIR__ . '/../../../../view/frontend/layout/furanform_thread_view.xml');
        $this->assertSame('false', (string)$layout->xpath('//block[@name="furanform.thread.view"]')[0]['cacheable']);
    }

    public function testItLivesOnTheFuranformRouteSoTheStorefrontKillSwitchApplies(): void
    {
        $routes = simplexml_load_file(__DIR__ . '/../../../../etc/frontend/routes.xml');
        $this->assertSame('furanform', (string)$routes->xpath('//route[@id="furanform"]')[0]['frontName']);
        $this->assertFileExists(__DIR__ . '/../../../../Controller/Thread/View.php');
        $this->assertFileExists(__DIR__ . '/../../../../Controller/Thread/Reply.php');
    }
}

