<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;

class EscaperFactoryTest extends TestCase
{
    public function testItCreatesAnEscaperThatEscapesHtmlAttributesAndUrlsWithoutAnObjectManager(): void
    {
        $escaper = EscaperFactory::create();

        $this->assertSame('a&#x20;&quot;b&quot;&#x3D;&lt;', $escaper->escapeHtmlAttr('a "b"=<'));
        $this->assertSame('&lt;b&gt;', $escaper->escapeHtml('<b>'));
        $this->assertSame('https&#x3A;&#x2F;&#x2F;shop.test&#x2F;x', $escaper->escapeHtmlAttr('https://shop.test/x'));
        $this->assertSame('https://shop.test/x?a=1&amp;b=2', $escaper->escapeUrl('https://shop.test/x?a=1&b=2'));
        $this->assertStringNotContainsString('javascript', $escaper->escapeUrl('javascript:alert(1)'));
    }
}
