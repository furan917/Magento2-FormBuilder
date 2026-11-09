<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation;

// phpcs:disable Magento2.Functions.StaticFunction -- stateless helper called from field types and normalisers that have no injected dependencies

/**
 * Input masks. A mask is a preset code (see PRESETS, same codes as BuiltInRules) or a custom pattern:
 * 9 = digit 0-9, a = letter, A = letter forced to upper case (ASCII only), * = any character, everything else is a
 * literal. Mirrored by `core.mask` in ffb-core.js (shared fixture Test/fixtures/masks.json).
 *
 * Formatting is linear, regex-free on user data and never drops typed letters or digits: input that stops fitting
 * the mask is kept after the formatted part so validation can report it. Invalid UTF-8 is returned unchanged.
 */
class Mask
{
    public const MAX_LENGTH = 40;

    private const PLACEHOLDERS = ['9', 'a', 'A', '*'];
    private const SPACE = " \t\n\r\f\x0B";
    private const PHONE_STRIP = self::SPACE . '().-';

    /**
     * mask: custom mask; phone: uk|intl; otherwise compact (strip, upper-case) then, when `when` matches, split
     * at `at` (negative = from the end) or in `group`s of N joined by `sep`.
     */
    private const PRESETS = [
        'uk_sort_code' => ['mask' => '99-99-99'],
        'iban_format' => ['strip' => '', 'when' => '/^[A-Z0-9]+$/D', 'group' => 4, 'sep' => ' '],
        'uk_postcode' => ['strip' => '', 'when' => '/^[A-Z0-9]{5,7}$/D', 'at' => -3, 'sep' => ' '],
        'us_zip' => ['strip' => '-', 'when' => '/^[0-9]{9}$/D', 'at' => 5, 'sep' => '-'],
        'ca_postal_code' => ['strip' => '', 'when' => '/^[A-Z0-9]{6}$/D', 'at' => 3, 'sep' => ' '],
        'ie_eircode' => ['strip' => '', 'when' => '/^[A-Z0-9]{7}$/D', 'at' => 3, 'sep' => ' '],
        'nl_postcode' => ['strip' => '', 'when' => '/^[0-9]{4}[A-Z]{2}$/D', 'at' => 4, 'sep' => ' '],
        'au_postcode' => ['strip' => '', 'when' => '/^[0-9]{4}$/D'],
        'uk_vat' => ['strip' => '', 'when' => '/^[A-Z0-9]+$/D'],
        'uk_phone' => ['phone' => 'uk'],
        'international_phone' => ['phone' => 'intl'],
    ];

    /**
     * @return string[]
     */
    public static function presetCodes(): array
    {
        return array_keys(self::PRESETS);
    }

    public static function isPreset(string $mask): bool
    {
        return isset(self::PRESETS[$mask]);
    }

    /** A preset code, or a custom mask of 9 / a / A / * placeholders, spaces and the punctuation - + ( ) . , / : # _ */
    public static function isValid(string $mask): bool
    {
        return self::isPreset($mask)
            || preg_match('/^(?=.{1,' . self::MAX_LENGTH . '}$)[\-+().,\/:#_ ]*[9aA*][9aA*\-+().,\/:#_ ]*$/Du', $mask) === 1;
    }

    public static function format(string $value, string $mask): string
    {
        if ($value === '' || $mask === '' || !self::isUtf8($value, $mask)) {
            return $value;
        }
        $spec = self::PRESETS[$mask] ?? null;
        if ($spec === null) {
            return self::walk($value, $mask)[0];
        }
        if (isset($spec['mask'])) {
            return self::walk($value, $spec['mask'])[0];
        }
        if (isset($spec['phone'])) {
            return $spec['phone'] === 'uk' ? self::ukPhone($value) : self::strip(trim($value), self::PHONE_STRIP);
        }
        return self::compact($value, $spec);
    }

    public static function raw(string $value, string $mask): string
    {
        if ($value === '' || $mask === '' || !self::isUtf8($value, $mask)) {
            return $value;
        }
        $spec = self::PRESETS[$mask] ?? null;
        if ($spec === null) {
            return self::walk($value, $mask)[1];
        }
        if (isset($spec['mask'])) {
            return self::walk($value, $spec['mask'])[1];
        }
        return self::strip($value, isset($spec['phone']) ? self::PHONE_STRIP : self::SPACE . $spec['strip']);
    }

    private static function isUtf8(string $value, string $mask): bool
    {
        return mb_check_encoding($value, 'UTF-8') && mb_check_encoding($mask, 'UTF-8');
    }

    /**
     * @return array{0: string, 1: string} formatted, raw
     */
    private static function walk(string $value, string $mask): array
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = preg_split('//u', $mask, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false || $tokens === false) {
            return [$value, $value];
        }
        $separators = self::separators($tokens);
        $count = count($chars);
        $i = 0;
        $out = '';
        $placed = '';
        foreach ($tokens as $token) {
            if ($i >= $count) {
                break;
            }
            if (!in_array($token, self::PLACEHOLDERS, true)) {
                if ($chars[$i] === $token) {
                    $i++;
                }
                $out .= $token;
                continue;
            }
            while ($i < $count && isset($separators[$chars[$i]])) {
                $i++;
            }
            if ($i >= $count || !self::accepts($token, $chars[$i])) {
                break;
            }
            $char = $token === 'A' ? self::upper($chars[$i]) : $chars[$i];
            $out .= $char;
            $placed .= $char;
            $i++;
        }
        $rest = implode('', array_slice($chars, $i));
        return [$out . $rest, $placed . $rest];
    }

    /**
     * Whitespace and the mask's non-alphanumeric literals are skipped when they sit where a placeholder expects input.
     *
     * @param string[] $tokens
     * @return array<string, true>
     */
    private static function separators(array $tokens): array
    {
        $separators = [];
        foreach (str_split(self::SPACE) as $space) {
            $separators[$space] = true;
        }
        foreach ($tokens as $token) {
            if (!in_array($token, self::PLACEHOLDERS, true) && preg_match('/^[\p{L}\p{N}]$/u', $token) !== 1) {
                $separators[$token] = true;
            }
        }
        return $separators;
    }

    private static function accepts(string $token, string $char): bool
    {
        if ($token === '9') {
            return strlen($char) === 1 && $char >= '0' && $char <= '9';
        }
        return $token === '*' || preg_match('/^\p{L}$/u', $char) === 1;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function compact(string $value, array $spec): string
    {
        $compact = self::upper(self::strip($value, self::SPACE . $spec['strip']));
        if (preg_match($spec['when'], $compact) !== 1) {
            return self::upper(trim($value));
        }
        if (isset($spec['group'])) {
            return implode($spec['sep'], str_split($compact, $spec['group']));
        }
        if (isset($spec['at'])) {
            $cut = $spec['at'] < 0 ? strlen($compact) + $spec['at'] : $spec['at'];
            return substr($compact, 0, $cut) . $spec['sep'] . substr($compact, $cut);
        }
        return $compact;
    }

    private static function ukPhone(string $value): string
    {
        $text = trim($value);
        if (preg_match('/^\+?[0-9 ().\-]*$/D', $text) !== 1) {
            return $text;
        }
        $digits = (string)preg_replace('/[^0-9]/', '', $text);
        if ($text !== '' && $text[0] === '+') {
            if (!str_starts_with($digits, '44')) {
                return $text;
            }
            $national = substr($digits, 2);
            if (strlen($national) === 11 && $national[0] === '0') {
                $national = substr($national, 1);
            }
            if (strlen($national) !== 10 || $national[0] === '0') {
                return $text;
            }
            return '+44 ' . ($national[0] === '2'
                ? substr($national, 0, 2) . ' ' . substr($national, 2, 4) . ' ' . substr($national, 6)
                : substr($national, 0, 4) . ' ' . substr($national, 4));
        }
        if (strlen($digits) !== 11 || $digits[0] !== '0' || $digits[1] === '0') {
            return $text;
        }
        return str_starts_with($digits, '02')
            ? substr($digits, 0, 3) . ' ' . substr($digits, 3, 4) . ' ' . substr($digits, 7)
            : substr($digits, 0, 5) . ' ' . substr($digits, 5);
    }

    private static function strip(string $value, string $chars): string
    {
        return str_replace(str_split($chars), '', $value);
    }

    private static function upper(string $value): string
    {
        return strtr($value, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }
}
