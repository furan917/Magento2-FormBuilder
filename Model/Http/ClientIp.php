<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Http;

use Furan\FormBuilder\Model\Config;
use Magento\Framework\App\Request\Http;

/**
 * Client address for rate limits and stored IPs. Reads REMOTE_ADDR itself rather than Magento's RemoteAddress,
 * which core di.xml configures to believe X-Forwarded-For from anyone. The header is only used when the
 * peer is a configured trusted proxy.
 */
class ClientIp
{
    public function __construct(
        private readonly Http $request,
        private readonly Config $config
    ) {
    }

    public function address(): string
    {
        $peer = $this->valid((string)$this->request->getServer('REMOTE_ADDR'));
        $trusted = $this->config->getTrustedProxies();
        if ($peer === '' || $trusted === [] || !$this->inAny($peer, $trusted)) {
            return $peer;
        }
        $hops = array_reverse(array_map(trim(...), explode(',', (string)$this->request->getServer('HTTP_X_FORWARDED_FOR'))));
        foreach ($hops as $hop) {
            $hop = $this->valid($hop);
            if ($hop === '') {
                break;
            }
            if (!$this->inAny($hop, $trusted)) {
                return $hop;
            }
        }

        return $peer;
    }

    /** Rate-limit key: IPv4 as is, IPv6 by /64 (one host usually owns the whole /64). */
    public function bucket(): string
    {
        return $this->bucketOf($this->address());
    }

    /** Pure: the bucket of an address already resolved through address(). */
    public function bucketOf(string $ip): string
    {
        if ($ip === '') {
            return 'unknown';
        }
        if (!str_contains($ip, ':')) {
            return $ip;
        }

        return inet_ntop(substr((string)inet_pton($ip), 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** Dual-stack servers report IPv4 visitors as ::ffff:a.b.c.d; unwrap them so they are bucketed and matched as IPv4. */
    private function valid(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        $packed = (string)inet_pton($ip);
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string)inet_ntop(substr($packed, 12));
        }

        return $ip;
    }

    /** @param string[] $ranges addresses or CIDR ranges */
    private function inAny(string $ip, array $ranges): bool
    {
        $packed = (string)inet_pton($ip);
        foreach ($ranges as $range) {
            [$base, $bits] = array_pad(explode('/', $range, 2), 2, null);
            $net = filter_var($base, FILTER_VALIDATE_IP) !== false ? inet_pton((string)$base) : false;
            if ($net === false || strlen($net) !== strlen($packed)) {
                continue;
            }
            $bits = $bits === null ? strlen($net) * 8 : max(0, min(strlen($net) * 8, (int)$bits));
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($packed, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }
            $mask = $rest === 0 ? 0 : (0xFF << (8 - $rest)) & 0xFF;
            if ($rest === 0 || ((ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask))) {
                return true;
            }
        }

        return false;
    }
}
