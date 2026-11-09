<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Content;

/**
 * Tiny markdown subset for rich_text items and thread messages (renderMessage). The whole text is HTML-escaped first (no entity pass-through); every transform then works on the escaped string and only ever introduces tags from this fixed set.
 */
class MiniMarkdown
{
    private const LINK = '/\[([^\[\]]+)\]\(([^()\s]*)\)/';
    private const URL_TAIL = '[^\s\\\\<>"\'`\x00-\x1F\x7F]*';
    // Message mode is mirrored by view/adminhtml/web/js/reply-markdown.js (Test/fixtures/reply-markdown.json), so it
    // uses explicit ASCII whitespace classes: PHP matches bytes, JS \s would also match NBSP.
    private const WS = ' \t\n\x0B\f\r';
    private const MSG_LINK = '/\[([^\[\]]+)\]\(([^()' . self::WS . ']*)\)/';
    private const MSG_HTTP = '~^https?://[A-Za-z0-9][^' . self::WS . '\\\\<>"\'`\x00-\x1F\x7F]*$~i';
    private const MSG_MAILTO = '/^mailto:[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{1,253}\.[A-Za-z]{2,24}$/';
    private const MSG_BULLET = '/^- ([^' . self::WS . '].*)$/';
    private const MSG_NUMBER = '/^\d{1,3}\. ([^' . self::WS . '].*)$/';
    private const MSG_REL = 'noopener noreferrer nofollow';

    public function render(string $text): string
    {
        $text = trim(str_replace(["\0", "\r\n", "\r"], ['', "\n", "\n"], $text));
        if ($text === '') {
            return '';
        }
        $blocks = [];
        foreach (preg_split('/\n{2,}/', $this->escape($text)) ?: [] as $chunk) {
            foreach ($this->chunkBlocks($chunk) as $block) {
                $blocks[] = $block;
            }
        }
        return implode("\n", $blocks);
    }

    /**
     * Admin replies: paragraphs, - and 1. lists, bold/italic, and links to http(s) or mailto only (never relative,
     * every link rel="nofollow"). Output uses only p, br, ul, ol, li, strong, em and a.
     */
    public function renderMessage(string $text): string
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
                if (preg_match(self::MSG_BULLET, $line, $match) === 1) {
                    [$lineKind, $line] = ['ul', $match[1]];
                } elseif (preg_match(self::MSG_NUMBER, $line, $match) === 1) {
                    [$lineKind, $line] = ['ol', $match[1]];
                } else {
                    $lineKind = 'p';
                }
                if ($kind !== null && $lineKind !== $kind) {
                    $blocks[] = $this->messageBlock($kind, $lines);
                    $lines = [];
                }
                $kind = $lineKind;
                $lines[] = $this->messageInline($line);
            }
            $blocks[] = $this->messageBlock((string)$kind, $lines);
        }
        return implode("\n", $blocks);
    }

    /** @param string[] $lines */
    private function messageBlock(string $kind, array $lines): string
    {
        if ($kind === 'p') {
            return '<p>' . implode("<br>\n", $lines) . '</p>';
        }
        return '<' . $kind . '><li>' . implode('</li><li>', $lines) . '</li></' . $kind . '>';
    }

    private function messageInline(string $escapedLine): string
    {
        $anchors = [];
        $line = preg_replace_callback(
            self::MSG_LINK,
            function (array $m) use (&$anchors): string {
                $url = htmlspecialchars_decode($m[2], ENT_QUOTES);
                $http = preg_match(self::MSG_HTTP, $url) === 1;
                if (!$http && preg_match(self::MSG_MAILTO, $url) !== 1) {
                    return $m[0];
                }
                $anchors[] = sprintf(
                    '<a href="%s" rel="%s"%s>%s</a>',
                    $this->escape($url),
                    self::MSG_REL,
                    $http ? ' target="_blank"' : '',
                    $this->messageEmphasis($m[1])
                );
                return "\0" . (count($anchors) - 1) . "\0";
            },
            $escapedLine
        ) ?? $escapedLine;
        $line = $this->messageEmphasis($line);
        return preg_replace_callback('/\x00(\d+)\x00/', static fn (array $m): string => $anchors[(int)$m[1]], $line) ?? $line;
    }

    private function messageEmphasis(string $escaped): string
    {
        $s = '[^' . self::WS . ']';
        $escaped = preg_replace('/\*\*\*(?=' . $s . ')(.+?)(?<=' . $s . ')\*\*\*/', '<strong><em>$1</em></strong>', $escaped)
            ?? $escaped;
        $escaped = preg_replace('/\*\*(?=' . $s . ')(.+?)(?<=' . $s . ')\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
        $t = '[^' . self::WS . '*]';
        return preg_replace('/\*(?=' . $t . ')([^*]+?)(?<=' . $t . ')\*/', '<em>$1</em>', $escaped) ?? $escaped;
    }

    /** @return string[] */
    private function chunkBlocks(string $chunk): array
    {
        $blocks = [];
        /** @var string[] $paragraph */
        $paragraph = [];
        /** @var string[] $list */
        $list = [];
        $flush = function () use (&$blocks, &$paragraph, &$list): void {
            if ($paragraph !== []) {
                $blocks[] = '<p>' . implode("<br>\n", array_map(
                    $this->inline(...),
                    $paragraph
                )) . '</p>';
                $paragraph = [];
            }
            if ($list !== []) {
                $blocks[] = '<ul>' . implode('', array_map(
                    fn (string $line): string => '<li>' . $this->inline($line) . '</li>',
                    $list
                )) . '</ul>';
                $list = [];
            }
        };
        foreach (explode("\n", $chunk) as $line) {
            $isBullet = preg_match('/^- (\S.*)$/', $line, $match) === 1;
            if ($isBullet && $paragraph !== []) {
                $flush();
            } elseif (!$isBullet && $list !== []) {
                $flush();
            }
            if ($isBullet) {
                $list[] = $match[1];
            } else {
                $paragraph[] = $line;
            }
        }
        $flush();
        return $blocks;
    }

    private function inline(string $escapedLine): string
    {
        $anchors = [];
        $line = preg_replace_callback(
            self::LINK,
            function (array $m) use (&$anchors): string {
                $anchor = $this->anchor($m[1], $m[2]);
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
        $escaped = preg_replace('/\*\*\*(?=\S)(.+?)(?<=\S)\*\*\*/', '<strong><em>$1</em></strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
        return preg_replace('/\*(?=[^\s*])([^*]+?)(?<=[^\s*])\*/', '<em>$1</em>', $escaped) ?? $escaped;
    }

    private function anchor(string $escapedLabel, string $escapedUrl): ?string
    {
        // Undo exactly one level of escaping so the URL is validated as the author typed it.
        $url = htmlspecialchars_decode($escapedUrl, ENT_QUOTES);
        $absolute = preg_match('~^https?://[A-Za-z0-9]' . self::URL_TAIL . '$~i', $url) === 1;
        $relative = preg_match('~^/(?![/\\\\])' . self::URL_TAIL . '$~', $url) === 1;
        $mailto = str_starts_with(strtolower($url), 'mailto:')
            && filter_var(substr($url, 7), FILTER_VALIDATE_EMAIL) !== false;
        if (!$absolute && !$relative && !$mailto) {
            return null;
        }
        return sprintf(
            '<a href="%s"%s>%s</a>',
            $this->escape($url),
            $absolute ? ' rel="noopener noreferrer" target="_blank"' : '',
            $this->emphasis($escapedLabel)
        );
    }
}
