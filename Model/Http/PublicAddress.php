<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Http;

/**
 * Keeps server-side requests to admin-configured hosts away from internal networks (SSRF).
 * IPv4 is a block-list of special-purpose ranges; IPv6 is an allow-list (2000::/3) minus special ranges.
 *
 * Only resolver output is cached; every lookup is re-classified and the connection is pinned to the classified IP.
 */
class PublicAddress
{
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    private const BLOCKED_V6 = [
        '::/96', '::ffff:0:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '100:0:0:1::/64',
        '2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20', '5f00::/16',
        'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    public const CACHE_TTL = 60;
    public const NEGATIVE_CACHE_TTL = 10;
    public const CACHE_MAX_HOSTS = 256;

    private const MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";
    private const NAT64_PREFIX = "\0\x64\xff\x9b\0\0\0\0\0\0\0\0";

    /** @var callable(string): string[] */
    private $resolver;

    /** @var callable(): int */
    private $clock;

    /** @var array<string, array{expires: int, ips: array<mixed>}> */
    private array $cache = [];

    public function __construct(?callable $resolver = null, ?callable $clock = null)
    {
        $this->resolver = $resolver ?? $this->dnsRecords(...);
        $this->clock = $clock ?? time(...);
    }

    /**
     * @param string[] $allowCidrs env.php dev bypass: addresses inside these ranges are also acceptable
     */
    public function isPublic(string $ip, bool $decodeNat64 = false, array $allowCidrs = []): bool
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16) {
            if (str_starts_with($packed, self::MAPPED_PREFIX)) {
                $packed = substr($packed, 12);
            } elseif ($decodeNat64 && str_starts_with($packed, self::NAT64_PREFIX)) {
                $packed = substr($packed, 12);
                $decoded = true;
            }
        }
        foreach ($allowCidrs as $cidr) {
            if (is_string($cidr) && $this->inRange($packed, $cidr)) {
                return true;
            }
        }
        if (isset($decoded)) {
            return $this->isPublicV4($packed);
        }
        return strlen($packed) === 4 ? $this->isPublicV4($packed) : $this->isPublicV6($packed);
    }

    /**
     * @return string|null the address to connect to; null when the host does not resolve or any address is internal
     */
    public function resolve(string $host): ?string
    {
        return $this->lookup($host)['ip'];
    }

    /**
     * @param string[] $allowCidrs
     * @return array{status: 'ok'|'unresolved'|'blocked', ip: ?string}
     */
    public function lookup(string $host, bool $decodeNat64 = false, array $allowCidrs = []): array
    {
        $host = trim($host, '[]');
        try {
            $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolveCached($host);
        } catch (\Throwable) {
            return ['status' => 'unresolved', 'ip' => null];
        }
        if ($ips === []) {
            return ['status' => 'unresolved', 'ip' => null];
        }
        foreach ($ips as $ip) {
            if (!is_string($ip) || !$this->isPublic($ip, $decodeNat64, $allowCidrs)) {
                return ['status' => 'blocked', 'ip' => null];
            }
        }
        return ['status' => 'ok', 'ip' => $this->pin((string)reset($ips))];
    }

    /** @return array<mixed> */
    private function resolveCached(string $host): array
    {
        $key = strtolower($host);
        $now = ($this->clock)();
        if (isset($this->cache[$key]) && $this->cache[$key]['expires'] > $now) {
            return $this->cache[$key]['ips'];
        }
        $ips = ($this->resolver)($host);
        unset($this->cache[$key]);
        if (count($this->cache) >= self::CACHE_MAX_HOSTS) {
            unset($this->cache[array_key_first($this->cache)]);
        }
        $this->cache[$key] = ['expires' => $now + ($ips === [] ? self::NEGATIVE_CACHE_TTL : self::CACHE_TTL), 'ips' => $ips];

        return $ips;
    }

    private function pin(string $ip): string
    {
        $packed = (string)inet_pton($ip);
        return str_starts_with($packed, self::MAPPED_PREFIX) ? (string)inet_ntop(substr($packed, 12)) : $ip;
    }

    private function isPublicV4(string $packed): bool
    {
        foreach (self::BLOCKED_V4 as $cidr) {
            if ($this->inRange($packed, $cidr)) {
                return false;
            }
        }
        return true;
    }

    private function isPublicV6(string $packed): bool
    {
        if (!$this->inRange($packed, '2000::/3')) {
            return false;
        }
        foreach (self::BLOCKED_V6 as $cidr) {
            if ($this->inRange($packed, $cidr)) {
                return false;
            }
        }
        return true;
    }

    /** Malformed CIDRs never match. */
    private function inRange(string $packed, string $cidr): bool
    {
        $parts = explode('/', $cidr);
        $base = count($parts) === 2 && filter_var($parts[0], FILTER_VALIDATE_IP) !== false
            ? inet_pton($parts[0]) : false;
        if ($base === false || strlen($base) !== strlen($packed) || preg_match('/^\d{1,3}$/D', $parts[1]) !== 1) {
            return false;
        }
        $bits = (int)$parts[1];
        if ($bits > strlen($base) * 8) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (substr($packed, 0, $bytes) !== substr($base, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($base[$bytes]) & $mask);
    }

    /**
     * Any resolver failure (Magento turns warnings into exceptions) yields no records, so the host is unresolved.
     *
     * @return string[]
     */
    private function dnsRecords(string $host): array
    {
        if ($host === '' || preg_match('/^[A-Za-z0-9.-]{1,253}$/D', $host) !== 1) {
            return [];
        }
        try {
            $ips = gethostbynamel($host) ?: [];
            // phpcs:ignore Magento2.Functions.DiscouragedFunction -- AAAA lookup has no framework equivalent
            foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = (string)$record['ipv6'];
                }
            }
        } catch (\Throwable) {
            return [];
        }
        return $ips;
    }
}
