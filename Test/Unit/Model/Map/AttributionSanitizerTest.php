<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Map;

use Furan\FormBuilder\Model\Map\AttributionSanitizer;
use PHPUnit\Framework\TestCase;

class AttributionSanitizerTest extends TestCase
{
    public function testItStripsEverythingButLinksFromTheAttribution(): void
    {
        $out = (new AttributionSanitizer())->sanitize(
            '&copy; <b>Map</b> <script>alert(1)</script><img src=x onerror=alert(1)> '
            . '<a href="https://www.openstreetmap.org/copyright">OSM</a>'
        );

        $this->assertSame(
            '&copy; Map alert(1) <a href="https://www.openstreetmap.org/copyright">OSM</a>',
            $out
        );
    }

    public function testItRemovesEventHandlersAndNonHttpLinksFromAttributionAnchors(): void
    {
        $s = new AttributionSanitizer();

        $this->assertSame(
            '<a href="https://a.test/x?a=1&amp;b=2">A</a>',
            $s->sanitize('<a href="https://a.test/x?a=1&b=2" onclick="evil()" style="x:y" target="_blank">A</a>')
        );
        $this->assertSame('Bad', $s->sanitize('<a href="javascript:alert(1)">Bad</a>'));
        $this->assertSame('Bad', $s->sanitize('<a href="data:text/html,x">Bad</a>'));
        $this->assertSame('&lt;x&gt; &quot;q&quot;', $s->sanitize('&lt;x&gt; "q"'));
    }
}
