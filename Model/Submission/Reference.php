<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

/**
 * UUIDv7 submission references. Stored as 16 raw bytes, shown as 8-4-4-4-12 lower-case hex.
 * Raw bytes never leave the model: readers format them with toString().
 */
class Reference
{
    public const BYTES = 16;
    public const PLACEHOLDER = '{{reference}}';

    /** @param int|null $nowMs Unix time in milliseconds; null = now */
    public static function generate(?int $nowMs = null): string
    {
        $nowMs ??= (int)floor(microtime(true) * 1000);
        $bytes = substr(pack('J', $nowMs), 2) . random_bytes(10);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return $bytes;
    }

    /** Empty string for anything that is not 16 bytes, so malformed raw data is never echoed. */
    public static function toString(string $bytes): string
    {
        if (strlen($bytes) !== self::BYTES) {
            return '';
        }

        return (string)preg_replace('/^(.{8})(.{4})(.{4})(.{4})(.{12})$/D', '$1-$2-$3-$4-$5', bin2hex($bytes));
    }

    /** @return string|null 16 bytes, or null when the text is not 32 hex digits (hyphens, spaces and case ignored) */
    public static function parse(string $text): ?string
    {
        $hex = (string)preg_replace('/[ \t\r\n-]+/', '', $text);
        if (preg_match('/^[0-9a-fA-F]{32}$/D', $hex) !== 1) {
            return null;
        }

        return (string)hex2bin($hex);
    }

    /** Hex digits and hyphens only, so the replacement cannot carry markup or template directives. */
    public static function fill(string $text, string $reference): string
    {
        return str_replace(self::PLACEHOLDER, $reference, $text);
    }
}
