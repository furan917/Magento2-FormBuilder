<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Http;

use Furan\FormBuilder\Api\Data\RequestPolicy;
use Furan\FormBuilder\Api\Data\SafeUrl;

/**
 * Parses an outbound URL strictly and rebuilds it from validated parts. The raw input is never returned.
 */
class UrlNormalizer
{
    private const MAX_URL_LENGTH = 2048;
    private const MAX_HOST_LENGTH = 253;
    private const DEFAULT_PORTS = ['https' => 443, 'http' => 80];
    private const IDNA_OPTIONS = IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ;
    private const LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /**
     * @throws UnsafeUrlException
     */
    public function normalize(string $url, RequestPolicy $policy): SafeUrl
    {
        $url = trim($url, " \t\n\r\v\f");
        if ($url === '') {
            throw new UnsafeUrlException('empty');
        }
        if (strlen($url) > self::MAX_URL_LENGTH) {
            throw new UnsafeUrlException('too_long');
        }
        $this->assertCleanCharacters($url);
        [$scheme, $authority, $path, $query] = $this->split($url);
        if ($scheme !== 'https' && !($scheme === 'http' && $policy->allowHttp)) {
            throw new UnsafeUrlException('scheme');
        }
        if (str_contains($authority, '@')) {
            throw new UnsafeUrlException('userinfo');
        }
        if (str_contains($authority, '%')) {
            throw new UnsafeUrlException('percent_in_authority');
        }
        [$rawHost, $rawPort] = $this->splitAuthority($authority);
        $port = $this->port($rawPort, $scheme, $policy);
        [$host, $isLiteral] = $this->host($rawHost, $policy);
        $this->assertAllowed($host, $policy);

        return new SafeUrl(
            $scheme,
            $host,
            $port,
            $this->encode($path, true) . $this->encodedQuery($query),
            $isLiteral
        );
    }

    private function assertCleanCharacters(string $url): void
    {
        $result = preg_match('/[\p{Z}\p{Cc}\p{Cf}]/u', $url);
        if ($result === false) {
            throw new UnsafeUrlException('malformed');
        }
        if ($result === 1) {
            throw new UnsafeUrlException('control_character');
        }
        if (str_contains($url, '\\')) {
            throw new UnsafeUrlException('backslash');
        }
        if (str_contains($url, '#')) {
            throw new UnsafeUrlException('fragment');
        }
    }

    /**
     * @return array{string, string, string, string}
     */
    private function split(string $url): array
    {
        if (preg_match('~^([A-Za-z][A-Za-z0-9+.-]*)://([^/?]*)(/[^?]*)?(?:\?(.*))?$~', $url, $m) !== 1) {
            throw new UnsafeUrlException('malformed');
        }
        return [strtolower($m[1]), $m[2], $m[3] ?? '', $m[4] ?? ''];
    }

    /**
     * @return array{string, ?string}
     */
    private function splitAuthority(string $authority): array
    {
        if (str_starts_with($authority, '[')) {
            if (preg_match('/^\[([^\]]*)\](?::(.*))?$/', $authority, $m) !== 1) {
                throw new UnsafeUrlException('host_invalid');
            }
            return ['[' . $m[1] . ']', $m[2] ?? null];
        }
        $colons = substr_count($authority, ':');
        if ($colons > 1) {
            throw new UnsafeUrlException('host_invalid');
        }
        if ($colons === 0) {
            return [$authority, null];
        }
        [$host, $port] = explode(':', $authority, 2) + [1 => ''];
        return [$host, $port];
    }

    private function port(?string $raw, string $scheme, RequestPolicy $policy): int
    {
        if ($raw === null) {
            $port = self::DEFAULT_PORTS[$scheme];
        } elseif (preg_match('/^[1-9][0-9]{0,4}$/', $raw) === 1) {
            $port = (int)$raw;
        } else {
            throw new UnsafeUrlException('port');
        }
        if (!in_array($port, $policy->ports, true)) {
            throw new UnsafeUrlException('port');
        }
        return $port;
    }

    /**
     * @return array{string, bool} ASCII host, whether it is an IP literal
     */
    private function host(string $raw, RequestPolicy $policy): array
    {
        if (str_starts_with($raw, '[')) {
            return [$this->literal(strtolower(substr($raw, 1, -1)), $policy, true), true];
        }
        $host = $this->toAscii($raw);
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            // A non-ASCII spelling of an IP (fullwidth digits, ideographic stops) is a differential vector.
            if ($host !== strtolower(rtrim($raw, '.'))) {
                throw new UnsafeUrlException('host_invalid');
            }
            return [$this->literal($host, $policy, false), true];
        }
        if (!$this->isDnsName($host)) {
            throw new UnsafeUrlException('host_invalid');
        }
        return [$host, false];
    }

    private function literal(string $literal, RequestPolicy $policy, bool $bracketed): string
    {
        if (!IpLiteral::isCanonical($literal) || ($bracketed && !str_contains($literal, ':'))) {
            throw new UnsafeUrlException('host_invalid');
        }
        if (!in_array($literal, $policy->ipLiteralHosts, true)) {
            throw new UnsafeUrlException('ip_literal_not_allowed');
        }
        return $literal;
    }

    private function toAscii(string $host): string
    {
        $ascii = preg_match('/^[\x00-\x7f]*$/', $host) === 1;
        if ($host === '' || (!$ascii && !function_exists('idn_to_ascii'))) {
            throw new UnsafeUrlException($host === '' ? 'host_invalid' : 'host_idn');
        }
        if (!function_exists('idn_to_ascii')) {
            return strtolower($host);
        }
        $converted = $this->idnToAscii($host);
        if ($converted === false || $converted === '') {
            throw new UnsafeUrlException($ascii && !str_contains(strtolower($host), 'xn--') ? 'host_invalid' : 'host_idn');
        }
        return strtolower($converted);
    }

    private function idnToAscii(string $host): string|false
    {
        // Magento's error handler turns any notice into an exception; fail closed instead.
        set_error_handler(static fn (): bool => true);
        try {
            return idn_to_ascii($host, self::IDNA_OPTIONS, INTL_IDNA_VARIANT_UTS46);
        } finally {
            restore_error_handler();
        }
    }

    private function isDnsName(string $host): bool
    {
        return strlen($host) <= self::MAX_HOST_LENGTH
            && preg_match('/^(?:' . self::LABEL . '\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $host) === 1;
    }

    private function assertAllowed(string $host, RequestPolicy $policy): void
    {
        if ($policy->hostAllowlist === []) {
            return;
        }
        foreach ($policy->hostAllowlist as $pattern) {
            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1);
                if (strlen($host) > strlen($suffix) && str_ends_with($host, $suffix)) {
                    return;
                }
            } elseif ($host === $pattern) {
                return;
            }
        }
        throw new UnsafeUrlException('host_not_allowed');
    }

    private function encodedQuery(string $query): string
    {
        return $query === '' ? '' : '?' . $this->encode($query, false);
    }

    /**
     * Re-encodes per RFC 3986: unreserved characters are decoded, everything outside pchar (and '@') is encoded.
     */
    private function encode(string $component, bool $isPath): string
    {
        if ($isPath) {
            $component = $component === '' ? '/' : $component;
            foreach (explode('/', $component) as $segment) {
                if ($this->decodeUnreserved($segment) === '.' || $this->decodeUnreserved($segment) === '..') {
                    throw new UnsafeUrlException('path_invalid');
                }
            }
        }
        $encoded = preg_replace_callback(
            '/%(.{0,2})|[^A-Za-z0-9\-._~!$&\'()*+,;=:\/?]/su',
            static function (array $m): string {
                if ($m[0][0] === '%') {
                    $hex = $m[1] ?? '';
                    if (preg_match('/^[0-9A-Fa-f]{2}$/', $hex) !== 1) {
                        throw new UnsafeUrlException('path_invalid');
                    }
                    $byte = (string)hex2bin($hex);
                    return preg_match('/^[A-Za-z0-9\-._~]$/', $byte) === 1 ? $byte : '%' . strtoupper($hex);
                }
                return strtoupper(rawurlencode($m[0]));
            },
            $component
        );
        if ($encoded === null) {
            throw new UnsafeUrlException('malformed');
        }
        return $encoded;
    }

    private function decodeUnreserved(string $segment): string
    {
        return str_ireplace(['%2e'], '.', $segment);
    }
}
