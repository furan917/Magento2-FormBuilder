<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

/** The scheme://host[:port] start of a URL; its path, query and credentials may hold a secret. */
class UrlOrigin
{
    public static function of(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return strtolower($parts['scheme']) . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
