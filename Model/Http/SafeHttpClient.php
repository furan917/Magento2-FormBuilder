<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Http;

use Furan\FormBuilder\Api\Data\HttpResult;
use Furan\FormBuilder\Api\Data\RequestPolicy;
use Furan\FormBuilder\Api\Data\SafeUrl;

/**
 * The only way module code makes outbound requests: resolve once, classify every record, pin the connection
 * to the validated address and refuse redirects, proxies and oversized responses. Never throws for network or
 * HTTP failures.
 */
class SafeHttpClient
{
    public const PREFLIGHT_OK = 'ok';
    public const PREFLIGHT_UNRESOLVED = 'unresolved';
    public const PREFLIGHT_BLOCKED = 'blocked';

    private const MAX_HEADER_BYTES = 16384;
    private const MAX_KEPT_VALUE = 255;
    private const KEPT_HEADERS = ['retry-after', 'location', 'content-type'];
    private const FORBIDDEN_HEADERS = ['host', 'content-length', 'transfer-encoding', 'connection', 'expect'];
    private const TLS_ERRNOS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    public function __construct(
        private readonly CurlSession $curl,
        private readonly PublicAddress $publicAddress
    ) {
    }

    /**
     * @return string one of the PREFLIGHT_* constants
     */
    public function preflight(SafeUrl $url, RequestPolicy $policy): string
    {
        return $this->lookup($url, $policy)['status'];
    }

    /**
     * @param array<string,string> $headers
     * @throws \InvalidArgumentException for programmer errors: bad method or header
     */
    public function request(
        string $method,
        SafeUrl $url,
        RequestPolicy $policy,
        array $headers = [],
        string $body = ''
    ): HttpResult {
        if ($method !== 'GET' && $method !== 'POST') {
            throw new \InvalidArgumentException('Only GET and POST are supported.');
        }
        $headerLines = $this->headerLines($headers);

        $started = hrtime(true);
        $lookup = $this->lookup($url, $policy);
        if ($lookup['status'] !== self::PREFLIGHT_OK || $lookup['ip'] === null) {
            return $this->failure($lookup['status'] === self::PREFLIGHT_BLOCKED
                ? HttpResult::ERROR_BLOCKED
                : HttpResult::ERROR_DNS);
        }

        try {
            return $this->send($method, $url, $policy, $headerLines, $body, $lookup['ip'], $started);
        } catch (\Throwable) {
            return $this->failure(HttpResult::ERROR_CONNECT, 0, $started);
        }
    }

    /**
     * @return array{status: string, ip: ?string}
     */
    private function lookup(SafeUrl $url, RequestPolicy $policy): array
    {
        $result = $this->publicAddress->lookup($url->host, $policy->decodeNat64, $policy->allowCidrs);
        return ['status' => $result['status'], 'ip' => $result['ip']];
    }

    /**
     * @param string[] $headerLines
     */
    private function send(
        string $method,
        SafeUrl $url,
        RequestPolicy $policy,
        array $headerLines,
        string $body,
        string $ip,
        int $started
    ): HttpResult {
        $handle = $this->curl->open();
        if ($handle === null) {
            return $this->failure(HttpResult::ERROR_UNSUPPORTED, 0, $started);
        }

        $keptHeaders = [];
        $headerBytes = 0;
        $headerOverflow = false;
        $responseBody = '';
        $truncated = false;
        $prereqBlocked = false;
        $cap = $policy->maxResponseBytes;
        $pin = $ip;
        $protocols = $policy->allowHttp ? CURLPROTO_HTTPS | CURLPROTO_HTTP : CURLPROTO_HTTPS;

        $options = [
            CURLOPT_URL => $url->toString(),
            CURLOPT_CONNECT_TO => ['::' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ':' . $url->port],
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => $protocols,
            CURLOPT_REDIR_PROTOCOLS => $protocols,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => $policy->connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $policy->timeoutMs,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => array_merge($headerLines, ['Expect:']),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (
                &$keptHeaders,
                &$headerBytes,
                &$headerOverflow
            ): int {
                $length = strlen($line);
                $headerBytes += $length;
                if ($headerBytes > self::MAX_HEADER_BYTES) {
                    $headerOverflow = true;
                    return 0;
                }
                if (str_starts_with($line, 'HTTP/')) {
                    $keptHeaders = [];
                } elseif (($colon = strpos($line, ':')) !== false) {
                    $name = strtolower(trim(substr($line, 0, $colon)));
                    if (in_array($name, self::KEPT_HEADERS, true)) {
                        $keptHeaders[$name] = substr(trim(substr($line, $colon + 1)), 0, self::MAX_KEPT_VALUE);
                    }
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$responseBody, &$truncated, $cap): int {
                $room = $cap - strlen($responseBody);
                if (strlen($chunk) > $room) {
                    $responseBody .= substr($chunk, 0, $room);
                    $truncated = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ];
        $options += $method === 'POST'
            ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]
            : [CURLOPT_HTTPGET => true];
        if (defined('CURLOPT_PREREQFUNCTION')) {
            $pinPort = $url->port;
            $options[CURLOPT_PREREQFUNCTION] = static function (
                $ch,
                string $primaryIp,
                string $localIp,
                int $primaryPort
            ) use (
                $pin,
                $pinPort,
                &$prereqBlocked
            ): int {
                $actual = filter_var($primaryIp, FILTER_VALIDATE_IP) === false ? false : inet_pton($primaryIp);
                if ($actual !== false && $actual === inet_pton($pin) && $primaryPort === $pinPort) {
                    return 0;
                }
                $prereqBlocked = true;
                return 1;
            };
        }

        foreach ($options as $option => $value) {
            if (!$this->curl->setOption($handle, $option, $value)) {
                return $this->failure(HttpResult::ERROR_UNSUPPORTED, 0, $started);
            }
        }

        $ok = $this->curl->execute($handle);
        $errno = $ok ? 0 : $this->curl->errorNumber($handle);
        $status = (int)$this->curl->info($handle, CURLINFO_RESPONSE_CODE);
        $primary = $this->curl->info($handle, CURLINFO_PRIMARY_IP);

        $errorClass = null;
        if (!$ok) {
            $errorClass = $this->classify($errno, $status, $truncated, $headerOverflow, $prereqBlocked);
        }

        return new HttpResult(
            $status,
            $keptHeaders,
            $responseBody,
            $truncated,
            $errorClass,
            $errno,
            (int)((hrtime(true) - $started) / 1000000),
            is_string($primary) && $primary !== '' ? $primary : null
        );
    }

    private function classify(int $errno, int $status, bool $truncated, bool $headerOverflow, bool $prereqBlocked): ?string
    {
        if ($prereqBlocked) {
            return HttpResult::ERROR_BLOCKED;
        }
        if ($headerOverflow || ($truncated && $status === 0)) {
            return HttpResult::ERROR_RESPONSE_TOO_LARGE;
        }
        if ($truncated) {
            return null;
        }
        return match (true) {
            $errno === 6 => HttpResult::ERROR_DNS,
            $errno === 28 => HttpResult::ERROR_TIMEOUT,
            in_array($errno, self::TLS_ERRNOS, true) => HttpResult::ERROR_TLS,
            $errno === 63 => HttpResult::ERROR_RESPONSE_TOO_LARGE,
            default => HttpResult::ERROR_CONNECT,
        };
    }

    private function failure(string $errorClass, int $errno = 0, ?int $started = null): HttpResult
    {
        $duration = $started === null ? 0 : (int)((hrtime(true) - $started) / 1000000);
        return new HttpResult(0, [], '', false, $errorClass, $errno, $duration);
    }

    /**
     * @param array<string|int,string> $headers
     * @return string[]
     */
    private function headerLines(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $name = (string)$name;
            if (
                preg_match('/^[A-Za-z0-9-]{1,64}$/D', $name) !== 1
                || in_array(strtolower($name), self::FORBIDDEN_HEADERS, true)
                || preg_match('/[\r\n\0]/', (string)$value) === 1
            ) {
                throw new \InvalidArgumentException('Invalid outbound header.');
            }
            $lines[] = $name . ': ' . $value;
        }
        return $lines;
    }
}
