<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Directory;

/**
 * Display name for an ISO2 country code in a locale; unknown or malformed codes come back unchanged.
 */
class CountryName
{
    /** @var array<string, array<string, string>> locale => code => name */
    private static array $names = [];

    public static function of(string $code, string $locale): string
    {
        if (isset(self::$names[$locale][$code])) {
            return self::$names[$locale][$code];
        }
        $name = $code;
        if (preg_match('/^[A-Z]{2}$/D', $code) && class_exists(\Locale::class)) {
            $display = \Locale::getDisplayRegion('-' . $code, $locale);
            $name = $display !== '' && $display !== false ? $display : $code;
        }
        return self::$names[$locale][$code] = $name;
    }
}
