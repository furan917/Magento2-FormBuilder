<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Map;

class AttributionSanitizer
{
    private const HREF_PATTERN = '#^https?://[^\s"\'<>]+$#i';

    /**
     * Rebuilds the input keeping only escaped text, &copy; and plain https?:// links.
     */
    public function sanitize(string $html): string
    {
        $out = '';
        $anchorOpen = false;
        $tokens = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            if ($token[0] !== '<') {
                $out .= $this->escapeText($token);
            } elseif (!$anchorOpen && ($href = $this->anchorHref($token)) !== null) {
                $out .= '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
                $anchorOpen = true;
            } elseif ($anchorOpen && preg_match('#^</a\s*>$#i', $token)) {
                $out .= '</a>';
                $anchorOpen = false;
            }
        }

        return $anchorOpen ? $out . '</a>' : $out;
    }

    private function escapeText(string $text): string
    {
        $escaped = htmlspecialchars(
            html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        return str_replace("\u{00A9}", '&copy;', $escaped);
    }

    private function anchorHref(string $tag): ?string
    {
        if (!preg_match('#^<a\s[^>]*>$#i', $tag)
            || !preg_match('/\shref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $m)
        ) {
            return null;
        }
        $href = html_entity_decode($m[1] !== '' ? $m[1] : ($m[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_match(self::HREF_PATTERN, $href) ? $href : null;
    }
}
