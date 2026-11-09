<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Thread;

/**
 * Cleans thread message bodies before they are measured and stored: invisible, bidi and control characters
 * cannot spoof or hide text, zalgo cannot overflow the layout, and blank padding cannot flood the thread.
 */
class MessageSanitizer
{
    public const MAX_LINES = 200;
    private const MAX_MARKS = 3;
    // Explicit ranges: \p{Extended_Pictographic} needs PCRE2 10.40+, older than some supported PHP 8.1 builds.
    private const EMOJI = '[\x{00A9}\x{00AE}\x{203C}\x{2049}\x{2122}\x{2139}\x{2194}-\x{21AA}\x{231A}-\x{23FF}'
        . '\x{24C2}\x{25AA}-\x{25FE}\x{2600}-\x{27BF}\x{2934}\x{2935}\x{2B05}-\x{2B55}\x{3030}\x{303D}\x{3297}'
        . '\x{3299}\x{1F000}-\x{1FAFF}]';

    /** '' for invalid UTF-8, which the callers then reject as empty. */
    public function clean(string $body): string
    {
        if (!mb_check_encoding($body, 'UTF-8')) {
            return '';
        }
        $body = (string)preg_replace('/\r\n?|[\x{2028}\x{2029}]/u', "\n", $body);
        $body = str_replace("\t", ' ', $body);
        $body = (string)preg_replace('/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}-\x{009F}]/u', '', $body);
        // Every Cf goes (bidi controls, zero-width chars, BOM, soft hyphen...) except two emoji uses:
        // ZWJ between emoji (family, profession, flag sequences) and tag characters inside a subdivision flag.
        $body = (string)preg_replace('/(?![\x{200C}\x{200D}\x{E0000}-\x{E007F}])\p{Cf}/u', '', $body);
        $body = (string)preg_replace_callback(
            '/\x{1F3F4}[\x{E0020}-\x{E007E}]{1,30}\x{E007F}|[\x{E0000}-\x{E007F}]+/u',
            static fn (array $m): string => str_starts_with($m[0], "\u{1F3F4}") ? $m[0] : '',
            $body
        );
        $body = (string)preg_replace(
            '/(?<!' . self::EMOJI . ')(?<!' . self::EMOJI . '\x{FE0F})\x{200D}|\x{200D}(?!' . self::EMOJI . ')/u',
            '',
            $body
        );
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($body, \Normalizer::FORM_C);
            if (!is_string($normalized)) {
                return '';
            }
            $body = $normalized;
        }
        $body = (string)preg_replace('/(\p{M}{' . self::MAX_MARKS . '})\p{M}+/u', '$1', $body);

        $body = (string)preg_replace('/[\h\p{Zs}]+$/mu', '', $body);
        $body = (string)preg_replace('/\n{3,}/', "\n\n", $this->trim($body));

        $lines = explode("\n", $body);
        if (count($lines) > self::MAX_LINES) {
            $rest = array_filter(array_slice($lines, self::MAX_LINES - 1), static fn (string $l): bool => $l !== '');
            $lines = array_slice($lines, 0, self::MAX_LINES - 1);
            $lines[] = implode(' ', $rest);
            $body = implode("\n", $lines);
        }

        return $this->trim($body);
    }

    private function trim(string $body): string
    {
        return (string)preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $body);
    }
}
