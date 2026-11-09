<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\Data;

use Furan\FormBuilder\Model\Http\IpLiteral;

/**
 * What an outbound request may do. Invalid entries are dropped and limits clamped, so a policy never widens by mistake.
 *
 * @api
 */
class RequestPolicy
{
    private const LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /** @var int[] */
    public readonly array $ports;
    /** @var string[] canonical IP literals, IPv6 without brackets */
    public readonly array $ipLiteralHosts;
    /** @var string[] exact hosts or *.suffix */
    public readonly array $hostAllowlist;
    /** @var string[] */
    public readonly array $allowCidrs;
    public readonly int $connectTimeoutMs;
    public readonly int $timeoutMs;
    public readonly int $maxResponseBytes;

    /**
     * @param int[] $ports
     * @param string[] $ipLiteralHosts
     * @param string[] $hostAllowlist
     * @param string[] $allowCidrs
     */
    public function __construct(
        public readonly bool $allowHttp = false,
        array $ports = [443],
        array $ipLiteralHosts = [],
        array $hostAllowlist = [],
        array $allowCidrs = [],
        public readonly bool $decodeNat64 = false,
        int $connectTimeoutMs = 3000,
        int $timeoutMs = 10000,
        int $maxResponseBytes = 65536
    ) {
        $this->ports = array_values(array_unique(array_filter(
            $ports,
            static fn ($port): bool => is_int($port) && $port >= 1 && $port <= 65535
        )));
        $this->ipLiteralHosts = $this->canonicalLiterals($ipLiteralHosts);
        $this->hostAllowlist = $this->validPatterns($hostAllowlist);
        $this->allowCidrs = array_values(array_filter($allowCidrs, $this->isCidr(...)));
        $this->connectTimeoutMs = max(1000, min(30000, $connectTimeoutMs));
        $this->timeoutMs = max(1000, min(30000, $timeoutMs));
        $this->maxResponseBytes = max(1024, min(1048576, $maxResponseBytes));
    }

    /**
     * @param mixed[] $entries
     * @return string[]
     */
    private function canonicalLiterals(array $entries): array
    {
        $result = [];
        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $literal = strtolower(preg_match('/^\[(.*)\]$/', $entry, $m) ? $m[1] : $entry);
            if (IpLiteral::isCanonical($literal)) {
                $result[$literal] = $literal;
            }
        }
        return array_values($result);
    }

    /**
     * @param mixed[] $entries
     * @return string[]
     */
    private function validPatterns(array $entries): array
    {
        $result = [];
        foreach ($entries as $entry) {
            $pattern = is_string($entry) ? strtolower($entry) : '';
            if (preg_match('/^(?:\*\.)?(?:' . self::LABEL . '\.)+[a-z][a-z0-9-]*$/', $pattern) === 1) {
                $result[$pattern] = $pattern;
            }
        }
        return array_values($result);
    }

    private function isCidr(mixed $cidr): bool
    {
        if (!is_string($cidr) || preg_match('/^([0-9a-f:.]+)\/(\d{1,3})$/i', $cidr, $m) !== 1) {
            return false;
        }
        $packed = filter_var($m[1], FILTER_VALIDATE_IP) === false ? false : inet_pton($m[1]);
        return $packed !== false && (int)$m[2] <= strlen($packed) * 8;
    }
}
