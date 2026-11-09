<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Directory;

/**
 * English display name for an ISO2 country code; unknown or malformed codes come back unchanged.
 */
class CountryName
{
    /** @var array<string, string> */
    private static array $names = [];

    public static function of(string $code): string
    {
        if (isset(self::$names[$code])) {
            return self::$names[$code];
        }
        $name = $code;
        if (preg_match('/^[A-Z]{2}$/D', $code) && class_exists(\Locale::class)) {
            $display = \Locale::getDisplayRegion('-' . $code, 'en');
            $name = $display !== '' && $display !== false ? $display : $code;
        }
        return self::$names[$code] = $name;
    }
}
