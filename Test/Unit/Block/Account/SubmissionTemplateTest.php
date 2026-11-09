<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Account;

use Furan\FormBuilder\Block\Account\Submission;
use Furan\FormBuilder\Model\Content\MiniMarkdown;
use Furan\FormBuilder\Model\Thread\MessageRenderer;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use PHPUnit\Framework\TestCase;

class SubmissionTemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../../view/frontend/templates/account/submission.phtml';

    private function render(array $overrides = []): string
    {
        $view = $overrides + [
            'title' => 'Contact', 'status' => 2, 'status_label' => 'Answered', 'created_at' => '2026-11-01',
            'can_edit' => false, 'edit_closed' => false, 'can_delete' => false, 'one_response' => false, 'can_reply' => true,
            'reply_state' => 'open', 'earlier' => false, 'before' => null, 'groups' => [],
            'thread' => [
                ['message_id' => 51, 'author' => 'admin', 'author_name' => 'Shop', 'body' => 'Hi', 'created_at' => 'd1'],
                ['message_id' => 52, 'author' => 'customer', 'author_name' => 'You', 'body' => '<b>x</b>', 'created_at' => 'd2'],
            ],
        ];
        $renderer = new MessageRenderer(EscaperFactory::create(), new MiniMarkdown());
        $block = $this->createStub(Submission::class);
        $block->method('getView')->willReturn($view);
        $block->method('getViewFileUrl')->willReturnCallback(static fn (string $f): string => 'https://shop.test/static/' . $f);
        $block->method('formatDate')->willReturnArgument(0);
        $block->method('getReplyUrl')->willReturn('https://shop.test/furanform/account/reply/id/9/');
        $block->method('getEarlierUrl')->willReturnCallback(static fn (int $b): string => 'https://shop.test/furanform/account/submission/id/9/before/' . $b . '/#ffb-thread');
        $block->method('getLatestUrl')->willReturn('https://shop.test/furanform/account/submission/id/9/#ffb-thread');
        $block->method('getBackUrl')->willReturn('https://shop.test/furanform/account/submissions/');
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

    public function testItRendersAPlainTextReplyBoxWithCounterAndTheReplyScript(): void
    {
        $html = $this->render();

        $this->assertMatchesRegularExpression('/<form class="ffb-account-reply"[^>]*data-ffb-reply>/', $html);
        $this->assertMatchesRegularExpression('/<textarea [^>]*data-ffb-max="5000"[^>]*data-ffb-reply-body/', $html);
        $this->assertStringContainsString('data-ffb-reply-count', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('Furan_FormBuilder::js/ffb-reply.js', $html);
        $this->assertStringNotContainsString('data-ffb-md', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
    }

    public function testItShowsAOneLineNoteWhenRepliesAreOffAndAThreadExists(): void
    {
        $html = $this->render(['can_reply' => false, 'reply_state' => 'off']);
        $this->assertStringNotContainsString('<form class="ffb-account-reply"', $html);
        $this->assertStringNotContainsString('ffb-reply.js', $html);
        $this->assertStringContainsString('Replies are closed for this form.', $html);

        $html = $this->render(['can_reply' => false, 'reply_state' => 'off', 'thread' => []]);
        $this->assertStringNotContainsString('Replies are closed', $html);
    }

    public function testItExplainsWhyEditingIsGoneWhenTheFormIsClosed(): void
    {
        $html = $this->render(['edit_closed' => true, 'can_delete' => true]);
        $this->assertStringContainsString('This form is closed, so answers can no longer be changed.', $html);
        $this->assertStringNotContainsString('class="action edit"', $html);
        $this->assertStringContainsString('class="action delete"', $html);

        $this->assertStringNotContainsString('can no longer be changed', $this->render());
    }

    public function testItReplacesTheReplyBoxWhenTheThreadIsFull(): void
    {
        $html = $this->render(['can_reply' => false, 'reply_state' => 'full']);

        $this->assertStringNotContainsString('<form class="ffb-account-reply"', $html);
        $this->assertStringContainsString('reached its message limit', $html);
    }

    public function testItLinksToEarlierAndLatestMessages(): void
    {
        $html = $this->render(['earlier' => true, 'before' => 900]);

        $this->assertStringContainsString('Showing 2 messages. Earlier messages are hidden.', $html);
        $this->assertStringContainsString('href="https://shop.test/furanform/account/submission/id/9/before/51/#ffb-thread"', $html);
        $this->assertStringContainsString('Show latest messages', $html);
        $this->assertStringNotContainsString('Showing', $this->render());
    }

    public function testItShowsTheReferenceOfASubmission(): void
    {
        $html = $this->render(['reference' => '0194f3a0-1111-7222-8333-444455556666']);

        $this->assertMatchesRegularExpression(
            '#<dt>Reference</dt>\s*<dd>0194f3a0-1111-7222-8333-444455556666</dd>#',
            $html
        );
    }
}
