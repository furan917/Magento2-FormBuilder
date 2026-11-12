<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Content;

/**
 * Markdown subset for rich_text items and thread messages. Text is HTML-escaped first, then transforms add only a
 * fixed tag set. Mirrored by builder-markdown.js and reply-markdown.js (Test/fixtures).
 */
class MiniMarkdown
{
    // Explicit ASCII whitespace classes: PHP matches bytes, JS \s would also match NBSP.
    private const WS = ' \t\n\x0B\f\r';
    private const LINK = '/\[([^\[\]]+)\]\(([^()' . self::WS . ']*)\)/';
    private const URL_TAIL = '[^' . self::WS . '\\\\<>"\'`\x00-\x1F\x7F]*';
    private const HTTP = '~^https?://[A-Za-z0-9]' . self::URL_TAIL . '$~i';
    private const RELATIVE = '~^/(?![/\\\\])' . self::URL_TAIL . '$~';
    private const MAILTO = '/^mailto:[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{1,253}\.[A-Za-z]{2,24}$/';
    private const BULLET = '/^- ([^' . self::WS . '].*)$/';
    private const NUMBER = '/^\d{1,3}\. ([^' . self::WS . '].*)$/';
    private const HEADING = '/^(#{1,4}) ([^' . self::WS . '].*)$/';
    private const MSG_REL = 'noopener noreferrer nofollow';

    /** Rich text: renderMessage's syntax plus # to ### as h3, #### as h4, site-relative links and a new-tab cue. */
    public function render(string $text): string
    {
        return $this->blocks($text, true);
    }

    /**
     * Admin replies: paragraphs, - and 1. lists, bold/italic, and links to http(s) or mailto only (never relative,
     * every link rel="nofollow"). Output uses only p, br, ul, ol, li, strong, em and a.
     */
    public function renderMessage(string $text): string
    {
        return $this->blocks($text, false);
    }

    private function blocks(string $text, bool $rich): string
    {
        $text = trim(str_replace(["\0", "\r\n", "\r"], ['', "\n", "\n"], $text));
        if ($text === '') {
            return '';
        }
        $blocks = [];
        foreach (preg_split('/\n{2,}/', $this->escape($text)) ?: [] as $chunk) {
            $kind = null;
            $lines = [];
            foreach (explode("\n", $chunk) as $line) {
                [$lineKind, $line] = $this->lineKind($line, $rich);
                if ($kind !== null && ($lineKind !== $kind || $kind[0] === 'h')) {
                    $blocks[] = $this->block($kind, $lines);
                    $lines = [];
                }
                $kind = $lineKind;
                $lines[] = $this->inline($line, $rich);
            }
            $blocks[] = $this->block((string)$kind, $lines);
        }
        return implode("\n", $blocks);
    }

    /** @return array{0: string, 1: string} block kind and the line without its marker */
    private function lineKind(string $line, bool $rich): array
    {
        if (preg_match(self::BULLET, $line, $match) === 1) {
            return ['ul', $match[1]];
        }
        if (preg_match(self::NUMBER, $line, $match) === 1) {
            return ['ol', $match[1]];
        }
        if ($rich && preg_match(self::HEADING, $line, $match) === 1) {
            return [strlen($match[1]) === 4 ? 'h4' : 'h3', $match[2]];
        }
        return ['p', $line];
    }

    /** @param string[] $lines */
    private function block(string $kind, array $lines): string
    {
        if ($kind === 'p') {
            return '<p>' . implode("<br>\n", $lines) . '</p>';
        }
        if ($kind[0] === 'h') {
            return '<' . $kind . '>' . $lines[0] . '</' . $kind . '>';
        }
        return '<' . $kind . '><li>' . implode('</li><li>', $lines) . '</li></' . $kind . '>';
    }

    private function inline(string $escapedLine, bool $rich): string
    {
        $anchors = [];
        $line = preg_replace_callback(
            self::LINK,
            function (array $m) use (&$anchors, $rich): string {
                $anchor = $this->anchor($m[1], $m[2], $rich);
                if ($anchor === null) {
                    return $m[0];
                }
                $anchors[] = $anchor;
                return "\0" . (count($anchors) - 1) . "\0";
            },
            $escapedLine
        ) ?? $escapedLine;
        $line = $this->emphasis($line);
        return preg_replace_callback('/\x00(\d+)\x00/', static fn (array $m): string => $anchors[(int)$m[1]], $line) ?? $line;
    }

    private function escape(string $text): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- Escaper needs ObjectManager; this class stays pure
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function emphasis(string $escaped): string
    {
        $s = '[^' . self::WS . ']';
        $escaped = preg_replace('/\*\*\*(?=' . $s . ')(.+?)(?<=' . $s . ')\*\*\*/', '<strong><em>$1</em></strong>', $escaped)
            ?? $escaped;
        $escaped = preg_replace('/\*\*(?=' . $s . ')(.+?)(?<=' . $s . ')\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
        $t = '[^' . self::WS . '*]';
        return preg_replace('/\*(?=' . $t . ')([^*]+?)(?<=' . $t . ')\*/', '<em>$1</em>', $escaped) ?? $escaped;
    }

    private function anchor(string $escapedLabel, string $escapedUrl, bool $rich): ?string
    {
        // Undo exactly one level of escaping so the URL is validated as the author typed it.
        $url = htmlspecialchars_decode($escapedUrl, ENT_QUOTES);
        $http = preg_match(self::HTTP, $url) === 1;
        $allowed = $http || preg_match(self::MAILTO, $url) === 1 || ($rich && preg_match(self::RELATIVE, $url) === 1);
        if (!$allowed) {
            return null;
        }
        if (!$rich) {
            $attributes = ' rel="' . self::MSG_REL . '"' . ($http ? ' target="_blank"' : '');
            return '<a href="' . $this->escape($url) . '"' . $attributes . '>' . $this->emphasis($escapedLabel) . '</a>';
        }
        $cue = $http
            ? '<span class="ffb-sr-only"> ' . $this->escape((string)__('(opens in a new tab)')) . '</span>'
            : '';
        return '<a href="' . $this->escape($url) . '"' . ($http ? ' rel="noopener noreferrer" target="_blank"' : '') . '>'
            . $this->emphasis($escapedLabel) . $cue . '</a>';
    }
}
