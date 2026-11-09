<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Thread;

use Furan\FormBuilder\Model\Content\MiniMarkdown;
use Furan\FormBuilder\Model\Thread\MessageRenderer;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use PHPUnit\Framework\TestCase;

class MessageRendererTest extends TestCase
{
    private function renderer(): MessageRenderer
    {
        return new MessageRenderer(EscaperFactory::create(), new MiniMarkdown());
    }

    public function testItRendersStoreRepliesAsSafeMarkdown(): void
    {
        $this->assertSame(
            '<p><strong>Hi</strong> <a href="https://e.com" rel="noopener noreferrer nofollow" target="_blank">x</a></p>',
            $this->renderer()->html('admin', '**Hi** [x](https://e.com)')
        );
    }

    public function testItKeepsCustomerAndSystemMessagesPlainText(): void
    {
        foreach (['customer', 'system', 'anything'] as $author) {
            $this->assertSame(
                '**Hi** [x](https://e.com) &lt;b&gt;<br />' . "\n" . 'two',
                $this->renderer()->html($author, "**Hi** [x](https://e.com) <b>\ntwo")
            );
        }
    }

    public function testItBraceEncodesEmailValuesSoTemplateDirectivesStayInert(): void
    {
        $this->assertSame('&#123;&#123;var x&#125;&#125;', MessageRenderer::braces('{{var x}}'));
        $html = $this->renderer()->emailHtml('customer', '{{template config_path="design/email/header_template"}}');
        $this->assertStringNotContainsString('{', $html);
        $this->assertStringNotContainsString('}', $html);
        $this->assertStringNotContainsString('{{', $this->renderer()->emailHtml('admin', '**{{var order}}**'));
    }

    public function testItKeepsEncodedBracesReadableInSubjectsAfterTheTemplateEscapeAndDecode(): void
    {
        // {{var subject}} escapes (without double-encoding), then TransportBuilder html_entity_decodes the subject.
        $escaped = EscaperFactory::create()->escapeHtml(MessageRenderer::braces('Re: {x} & y'));
        $this->assertSame('Re: &#123;x&#125; &amp; y', $escaped);
        $this->assertSame('Re: {x} & y', html_entity_decode($escaped, ENT_QUOTES));
    }
}

