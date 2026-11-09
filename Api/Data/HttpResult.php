<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\Data;

/**
 * Outcome of one outbound request. Carries error classes only, never curl error text (it can echo query tokens).
 *
 * @api
 */
class HttpResult
{
    public const ERROR_DNS = 'dns';
    public const ERROR_BLOCKED = 'blocked';
    public const ERROR_CONNECT = 'connect';
    public const ERROR_TLS = 'tls';
    public const ERROR_TIMEOUT = 'timeout';
    public const ERROR_RESPONSE_TOO_LARGE = 'response_too_large';
    public const ERROR_UNSUPPORTED = 'unsupported';

    /**
     * @param array<string,string> $headers lower-case names: retry-after, location, content-type
     */
    public function __construct(
        public readonly int $status = 0,
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly bool $bodyTruncated = false,
        public readonly ?string $errorClass = null,
        public readonly int $errno = 0,
        public readonly int $durationMs = 0,
        public readonly ?string $connectedIp = null
    ) {
    }
}
