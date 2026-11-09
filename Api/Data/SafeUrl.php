<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\Data;

/**
 * A URL rebuilt from validated parts; the only form that may reach a transport.
 *
 * @api
 */
class SafeUrl
{
    private const DEFAULT_PORTS = ['https' => 443, 'http' => 80];

    /**
     * @param string $host ASCII host; IPv6 literals without brackets
     */
    public function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly string $pathAndQuery,
        public readonly bool $isIpLiteral
    ) {
    }

    public function toString(): string
    {
        $host = $this->isIpLiteral && str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;
        $port = $this->port === (self::DEFAULT_PORTS[$this->scheme] ?? 0) ? '' : ':' . $this->port;
        return $this->scheme . '://' . $host . $port . $this->pathAndQuery;
    }
}
