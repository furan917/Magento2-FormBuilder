<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Http;

/**
 * The address as it is stored: IPv4 without the last octet, IPv6 without everything after the third hextet.
 */
class IpAnonymizer
{
    public function anonymize(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return implode('.', array_slice(explode('.', $ip), 0, 3)) . '.0';
        }
        $packed = (string)inet_pton($ip);
        $hextets = array_slice(str_split(bin2hex($packed), 4), 0, 3);

        return implode(':', array_map(static fn(string $h): string => dechex((int)hexdec($h)), $hextets)) . '::';
    }
}
