<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Adminhtml\Submission;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Block\Adminhtml\Submission\View;
use Furan\FormBuilder\Model\Content\MiniMarkdown;
use Furan\FormBuilder\Model\Submission\AnswerView;
use Furan\FormBuilder\Model\Submission\ValueFormatter;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ViewTemplateTest extends TestCase
{
    private bool $canDownload = true;
    private bool $canChangeStatus = true;
    private bool $canReply = false;
    private array $thread = [];
    private bool $earlier = false;
    private ?int $before = null;
    private ?int $customerId = null;
    /** @var array<string, \Furan\FormBuilder\Api\FieldTypeInterface> */
    private array $extraTypes = [];

    private const TEMPLATE = __DIR__ . '/../../../../../view/adminhtml/templates/submission/view.phtml';

    public function testItEscapesLabelsAndValuesOnTheViewPage(): void
    {
        $payload = [
            ['name' => 'a', 'label' => '<script>alert(1)</script>', 'type' => 'text', 'value' => '"><img src=x onerror=alert(2)>'],
            ['name' => 'b', 'label' => 'Multi', 'type' => 'textarea', 'value' => "line <b>one</b>\nline two"],
            ['name' => 'c', 'label' => 'List', 'type' => 'checkboxes', 'value' => ['<i>x</i>', 'y']],
        ];
        $html = $this->render($payload, '<u>Title</u>', '10.0.0.1<script>');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringNotContainsString('<u>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;one&lt;/b&gt;<br', $html);
        $this->assertStringContainsString('<li>&lt;i&gt;x&lt;/i&gt;</li>', $html);
        $this->assertStringContainsString('&lt;em&gt;Page&lt;/em&gt;', $html);
        $this->assertStringContainsString('&lt;s&gt;Sec&lt;/s&gt;', $html);
        $this->assertStringContainsString('3 of 3 answered', $html);
    }

    public function testItShowsTheReferenceOnTheAdminViewPage(): void
    {
        $html = $this->render([['name' => 'p', 'label' => 'Phone', 'type' => 'tel', 'value' => '1']], 'Form', null);

        $this->assertMatchesRegularExpression(
            '#class="ffb-sub-reference">Reference: <code>0194f3a0-1111-7222-8333-444455556666</code>#',
            $html
        );
    }

    public function testItPrintsTheFieldTypesOwnHtmlForCustomTypesInTheAdminView(): void
    {
        $html = $this->render([
            ['name' => 'p', 'label' => 'Spot', 'type' => 'point', 'value' => ['x' => '<i>1</i>', 'y' => '2']],
        ], 'Form', null);

        $this->assertStringContainsString('<b>P[&lt;i&gt;1&lt;/i&gt;|2]</b>', $html);
        $this->assertStringNotContainsString('<i>', $html);
    }

    public function testItShowsMapLinksFromTheMapTypesHtml(): void
    {
        $html = $this->render([
            ['name' => 'm', 'label' => 'Where', 'type' => 'map', 'value' => ['lat' => '51.500000', 'lng' => '-0.127800', 'zoom' => 12, 'label' => 'Home']],
        ], 'Form', null);

        $this->assertStringContainsString('Home (51.500000, -0.127800)', $html);
        $this->assertStringContainsString('https://www.openstreetmap.org/?mlat=51.500000', $html);
        $this->assertStringContainsString('ffb-sub-map-links', $html);
    }

    public function testItMarksUnansweredRowsAndShowsGuest(): void
    {
        $html = $this->render([['name' => 'p', 'label' => 'Phone', 'type' => 'tel', 'value' => '']], 'Form', null);

        $this->assertStringContainsString('ffb-sub-row is-empty', $html);
        $this->assertStringContainsString('Not answered', $html);
        $this->assertStringContainsString('0 of 1 answered', $html);
        $this->assertStringContainsString('Guest', $html);
        $this->assertStringNotContainsString('IP address', $html);
    }

    public function testItRendersFileEntriesWithADownloadLink(): void
    {
        $file = ['file' => 'furan_formbuilder/1/2026/01/' . str_repeat('a', 32) . '.pdf', 'name' => '<b>cv</b>.pdf', 'size' => 2048, 'mime' => 'application/pdf'];
        $html = $this->render(
            [['name' => 'cv', 'label' => 'CV', 'type' => 'file', 'value' => $file]],
            'Form',
            null
        );

        $this->assertStringContainsString('href="https://admin.test/download/id/5/field/cv"', $html);
        $this->assertStringContainsString('&lt;b&gt;cv&lt;/b&gt;.pdf', $html);
        $this->assertStringContainsString('2 KB', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('furan_formbuilder/', $html);
    }

    public function testItHidesTheDownloadLinkWithoutFileRights(): void
    {
        $this->canDownload = false;
        $file = ['file' => 'furan_formbuilder/1/2026/01/' . str_repeat('a', 32) . '.pdf', 'name' => 'cv.pdf', 'size' => 2048, 'mime' => 'application/pdf'];
        $html = $this->render([['name' => 'cv', 'label' => 'CV', 'type' => 'file', 'value' => $file]], 'Form', null);
        $this->assertStringContainsString('cv.pdf', $html);
        $this->assertStringNotContainsString('ffb-sub-download', $html);
    }

    public function testItRendersAKeyedStatusFormOnlyWhenTheReplyPermissionIsHeld(): void
    {
        $html = $this->render([], 'Form', null);
        $this->assertStringContainsString('action="https://admin.test/saveStatus/id/5"', $html);
        $this->assertStringContainsString('name="form_key" type="hidden" value="KEY123"', $html);
        $this->assertStringContainsString('<option value="1" selected', $html);
        $this->assertStringContainsString('&lt;b&gt;In progress&lt;/b&gt;', $html);

        $this->canChangeStatus = false;
        $html = $this->render([], 'Form', null);
        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringNotContainsString('saveStatus', $html);
        $this->assertStringContainsString('In progress', $html);
    }

    public function testItRendersStoreRepliesAsMarkdownAndCustomerMessagesAsText(): void
    {
        $this->thread = [
            ['author' => 'admin', 'author_name' => 'Ann', 'body' => '**Done** [x](javascript:alert(1))', 'created_at' => 'd', 'emailed_at' => 'd'],
            ['author' => 'customer', 'author_name' => 'Customer', 'body' => "**raw** <img src=x>", 'created_at' => 'd'],
        ];
        $html = $this->render([], 'Form', null);

        $this->assertStringContainsString('<strong>Done</strong>', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertStringContainsString('**raw** &lt;img src=x&gt;', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function testItRendersAnAccessibleMarkdownEditorThatKeepsSubmitOnce(): void
    {
        $this->canReply = true;
        $html = $this->render([], 'Form', null);

        $this->assertMatchesRegularExpression('/<form class="ffb-sub-reply" data-ffb-submit-once data-ffb-reply-editor method="post" action="https:\/\/admin.test\/reply\/id\/5">/', $html);
        foreach (['bold' => 'Bold (Ctrl+B)', 'italic' => 'Italic (Ctrl+I)', 'link' => 'Link (Ctrl+K)', 'bullets' => 'Bulleted list', 'numbers' => 'Numbered list'] as $action => $label) {
            $this->assertMatchesRegularExpression(
                '/<button type="button" data-ffb-md="' . $action . '" aria-label="' . preg_quote($label, '/') . '"/',
                html_entity_decode($html, ENT_QUOTES | ENT_HTML5)
            );
        }
        $this->assertStringContainsString('data-ffb-md-tab="write"', $html);
        $this->assertStringContainsString('data-ffb-md-tab="preview"', $html);
        $this->assertStringContainsString('data-ffb-md-head hidden', $html);
        $this->assertStringContainsString('name="body"', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function testItLinksToEarlierMessagesInsteadOfDroppingThem(): void
    {
        $this->thread = [
            ['message_id' => 120, 'author' => 'customer', 'author_name' => 'Customer', 'body' => 'a', 'created_at' => 'd'],
            ['message_id' => 121, 'author' => 'customer', 'author_name' => 'Customer', 'body' => 'b', 'created_at' => 'd'],
        ];
        $this->assertStringNotContainsString('Show earlier messages', $this->render([], 'Form', null));

        $this->earlier = true;
        $this->before = 500;
        $html = $this->render([], 'Form', null);

        $this->assertStringContainsString('Showing 2 messages. Earlier messages are hidden.', $html);
        $this->assertStringContainsString('href="https://admin.test/view/id/5/before/120"', $html);
        $this->assertStringContainsString('href="https://admin.test/view/id/5"', $html);
    }

    public function testItOffersAKeyedRevokeLinksFormForGuestSubmissionsOnly(): void
    {
        $this->canReply = true;
        $html = $this->render([], 'Form', null);
        $this->assertMatchesRegularExpression('/<form class="ffb-sub-revoke"[^>]*method="post" action="https:\/\/admin.test\/revokeLinks\/id\/5">\s*<input name="form_key" type="hidden" value="KEY123">/', $html);

        $this->customerId = 12;
        $this->assertStringNotContainsString('revokeLinks', $this->render([], 'Form', null));
        $this->customerId = null;
        $this->canReply = false;
        $this->assertStringNotContainsString('revokeLinks', $this->render([], 'Form', null));
    }

    public function testItLoadsTheEditorScriptsThroughTheLayoutHead(): void
    {
        $layout = (string)file_get_contents(__DIR__ . '/../../../../../view/adminhtml/layout/furanform_submission_view.xml');
        $this->assertStringContainsString('<script src="Furan_FormBuilder::js/reply-markdown.js" defer="defer"/>', $layout);
        $this->assertStringContainsString('<script src="Furan_FormBuilder::js/reply-editor.js" defer="defer"/>', $layout);
    }

    private function render(array $payload, string $formTitle, ?string $ip): string
    {
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getId')->willReturn(5);
        $submission->method('getStoreId')->willReturn(1);
        $submission->method('getCustomerId')->willReturnCallback(fn () => $this->customerId);
        $submission->method('getRemoteIp')->willReturn($ip);
        $submission->method('getReference')->willReturn('0194f3a0-1111-7222-8333-444455556666');
        $submission->method('getCreatedAt')->willReturn('2026-01-01 10:00:00');
        $block = $this->createStub(View::class);
        $block->method('getSubmission')->willReturn($submission);
        $block->method('getPayload')->willReturn($payload);
        $block->method('getFormTitle')->willReturn($formTitle);
        $pool = FieldPools::adminView($this->extraTypes);
        $answers = new AnswerView(new ValueFormatter($pool), $pool);
        $block->method('getAnswerGroups')->willReturn([[
            'title' => '<em>Page</em>',
            'removed' => false,
            'sections' => [['title' => '<s>Sec</s>', 'entries' => array_map(static fn (array $e) => $e + ['field' => null], $payload)]],
        ]]);
        $block->method('getDisplayValues')->willReturnCallback($answers->values(...));
        $block->method('getDisplayKind')->willReturnCallback($answers->kind(...));
        $block->method('getDisplayHtml')->willReturnCallback($answers->html(...));
        $block->method('isAnswered')->willReturnCallback($answers->isAnswered(...));
        $block->method('isFile')->willReturnCallback($answers->isFile(...));
        $block->method('getSubmittedAt')->willReturn('1 Jan 2026, 10:00:00');
        $block->method('getStoreName')->willReturn('Default Store View');
        $block->method('formatFileSize')->willReturn('2 KB');
        $block->method('getDownloadUrl')->willReturnCallback(
            fn(string $field): string => $this->canDownload ? 'https://admin.test/download/id/5/field/' . $field : ''
        );
        $block->method('canChangeStatus')->willReturnCallback(fn(): bool => $this->canChangeStatus);
        $block->method('getStatusOptions')->willReturn([['value' => 0, 'label' => 'New'], ['value' => 1, 'label' => '<b>In progress</b>']]);
        $block->method('getStatusLabel')->willReturn('In progress');
        $block->method('getCurrentStatus')->willReturn(1);
        $block->method('getSaveStatusUrl')->willReturn('https://admin.test/saveStatus/id/5');
        $block->method('getFormKey')->willReturn('KEY123');
        $block->method('getBackUrl')->willReturn('https://admin.test/index');
        $block->method('canReply')->willReturnCallback(fn (): bool => $this->canReply);
        $block->method('getThread')->willReturnCallback(fn (): array => $this->thread);
        $block->method('hasEarlierMessages')->willReturnCallback(fn (): bool => $this->earlier);
        $block->method('getBefore')->willReturnCallback(fn (): ?int => $this->before);
        $block->method('getEarlierUrl')->willReturnCallback(static fn (int $b): string => 'https://admin.test/view/id/5/before/' . $b);
        $block->method('getLatestUrl')->willReturn('https://admin.test/view/id/5');
        $block->method('getReplyUrl')->willReturn('https://admin.test/reply/id/5');
        $block->method('getRevokeLinksUrl')->willReturn('https://admin.test/revokeLinks/id/5');
        $messageRenderer = new \Furan\FormBuilder\Model\Thread\MessageRenderer(EscaperFactory::create(), new MiniMarkdown());
        $block->method('renderMessage')->willReturnCallback(
            static fn (array $m): string => $messageRenderer->html((string)$m['author'], (string)$m['body'])
        );

        $escaper = EscaperFactory::create();
        $renderer = static function () use ($block, $escaper): string {
            ob_start();
            include self::TEMPLATE;
            return (string)ob_get_clean();
        };
        return $renderer();
    }

    public function testItRendersCustomFileTypeEntriesWithADownloadLink(): void
    {
        $file = ['file' => 'furan_formbuilder/1/2026/01/' . str_repeat('a', 32), 'name' => 'plan.pdf', 'size' => 2048, 'mime' => 'application/pdf'];
        $html = $this->render([['name' => 'plan', 'label' => 'Plan', 'type' => 'doc', 'value' => $file]], 'Form', null);

        $this->assertStringContainsString('href="https://admin.test/download/id/5/field/plan"', $html);
        $this->assertStringContainsString('ffb-sub-file-name', $html);
    }

    /** @return array<string, array{0: array, 1: string}> */
    public static function layoutCases(): array
    {
        return [
            'matrix' => [['type' => 'matrix', 'value' => ['Speed: Very', 'Price: Low']],
                '<dl class="ffb-sub-matrix"><div><dt>Speed</dt><dd>Very</dd></div><div><dt>Price</dt><dd>Low</dd></div></dl>'],
            'list' => [['type' => 'list', 'value' => ['one', '<i>two</i>']],
                '<ol class="ffb-sub-list"><li>one</li><li>&lt;i&gt;two&lt;/i&gt;</li></ol>'],
            'checkboxes' => [['type' => 'checkboxes', 'value' => ['a', 'b']], '<ul class="ffb-sub-chips"><li>a</li><li>b</li></ul>'],
            'multiselect' => [['type' => 'multiselect', 'value' => ['a']], '<ul class="ffb-sub-chips"><li>a</li></ul>'],
            'rating' => [['type' => 'rating', 'value' => '3', 'field' => ['settings' => ['max' => 5]]],
                '<span class="ffb-sub-stars" aria-hidden="true">★★★<span>★★</span></span> 3 of 5'],
            'scale' => [['type' => 'scale', 'value' => '7', 'field' => ['settings' => ['min' => 0, 'max' => 10]]],
                '<span class="ffb-sub-score">7</span><span class="ffb-sub-muted">on a 0 to 10 scale</span>'],
            'scale without stored bounds' => [['type' => 'scale', 'value' => '4', 'field' => ['settings' => []]],
                '<span class="ffb-sub-score">4</span><span class="ffb-sub-muted">on a 1 to 5 scale</span>'],
            'checkbox yes' => [['type' => 'checkbox', 'value' => 'yes'], '<span class="ffb-sub-yes">Yes</span>'],
            'toggle no' => [['type' => 'toggle', 'value' => ''], '<span class="ffb-sub-muted">No</span>'],
            'consent yes' => [['type' => 'consent', 'value' => 'yes'], '<span class="ffb-sub-yes">Yes</span>'],
            'newsletter yes' => [['type' => 'newsletter', 'value' => 'yes'], '<span class="ffb-sub-yes">Yes</span>'],
            'color' => [['type' => 'color', 'value' => '#aabbcc'],
                '<span class="ffb-sub-swatch" style="background:&#x23;aabbcc"></span> #aabbcc'],
            'textarea' => [['type' => 'textarea', 'value' => "a <b>\nb"], '<div class="ffb-sub-long">a &lt;b&gt;<br />'],
        ];
    }

    #[DataProvider('layoutCases')]
    public function testItKeepsTheAdminAnswerLayoutOfEachBuiltInKind(array $entry, string $expected): void
    {
        $html = $this->render([['name' => 'x', 'label' => 'X'] + $entry], 'Form', null);

        $this->assertStringContainsString($expected, $this->squash($html), $entry['type']);
    }

    public function testItLaysOutAnswersOfCustomTypesByTheirKindWithoutTypeCodeChecks(): void
    {
        $kinds = ['pairs' => 'ffb-sub-matrix', 'list' => 'ffb-sub-list', 'chips' => 'ffb-sub-chips',
            'rating' => 'ffb-sub-stars', 'scale' => 'ffb-sub-score', 'boolean' => 'ffb-sub-yes', 'color' => 'ffb-sub-swatch',
            'multiline' => 'ffb-sub-long'];
        foreach ($kinds as $kind => $marker) {
            $this->extraTypes = ['custom' => new \Furan\FormBuilder\Test\Unit\Helper\AdminViewKindType('custom', $kind)];
            $value = $kind === 'color' ? '#aabbcc' : ($kind === 'pairs' ? ['a: b'] : ($kind === 'boolean' ? 'yes' : '3'));
            $html = $this->render([['name' => 'x', 'label' => 'X', 'type' => 'custom', 'value' => $value]], 'Form', null);

            $this->assertStringContainsString($marker, $html, $kind);
        }
    }

    public function testItKeepsPlainLayoutForTypesThatNoLongerExist(): void
    {
        $html = $this->render([['name' => 'x', 'label' => 'X', 'type' => 'gone', 'value' => ['a', 'b']]], 'Form', null);

        $this->assertStringContainsString('a, b', $html);
        $this->assertStringNotContainsString('ffb-sub-chips', $html);
    }

    private function squash(string $html): string
    {
        return trim((string)preg_replace('/>\s+</', '><', (string)preg_replace('/\s+/', ' ', $html)));
    }
}
