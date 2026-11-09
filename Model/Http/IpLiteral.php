<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Http;

class IpLiteral
{
    /**
     * True only for the one canonical spelling of an IPv4 or IPv6 address (no zone id, no brackets).
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- a pure string predicate used by the static URL helpers
    public static function isCanonical(string $value): bool
    {
        if (str_contains($value, '%') || filter_var($value, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $packed = inet_pton($value);
        return $packed !== false && inet_ntop($packed) === $value;
    }
}
