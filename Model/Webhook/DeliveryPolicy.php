<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

/** Pure delivery policy: turns one attempt's outcome into the next step. */
class DeliveryPolicy
{
    public const DISABLE_GONE = 'gone';
    public const DISABLE_FAILING = 'failing';
    private const FINAL_ERRORS = [
        DeliveryStatus::ERROR_PAYLOAD_TOO_LARGE,
        DeliveryStatus::ERROR_SECRET_UNAVAILABLE,
        DeliveryStatus::ERROR_UNSUPPORTED,
    ];

    private const DELAYS = [300, 1800, 7200, 18000, 36000, 50400, 72000];
    public const MAX_ATTEMPTS = 8;
    public const WINDOW = 259200;
    private const CIRCUIT_THRESHOLD = 5;
    private const JITTER = 0.2;
    private const MAX_RETRY_AFTER = 21600;
    private const HTTP_DATE = 'D, d M Y H:i:s \\G\\M\\T';

    public function __construct(private readonly RandomFraction $random)
    {
    }

    /**
     * @param array{failing_since: ?string, consecutive_failures: int} $endpointState
     */
    public function decide(Outcome $outcome, int $attempt, string $createdAt, array $endpointState, int $now): Decision
    {
        if ($outcome->status >= 200 && $outcome->status < 300) {
            return new Decision(DeliveryStatus::SUCCEEDED, null, null, null, null, true);
        }
        if ($outcome->status === 410) {
            return new Decision(DeliveryStatus::FAILED, null, DeliveryStatus::ERROR_ENDPOINT_GONE, self::DISABLE_GONE, null, false);
        }
        if ($outcome->errorClass === DeliveryStatus::ERROR_BLOCKED) {
            return new Decision(DeliveryStatus::BLOCKED, null, DeliveryStatus::ERROR_BLOCKED, null, null, false);
        }
        if (in_array($outcome->errorClass, self::FINAL_ERRORS, true)) {
            return new Decision(DeliveryStatus::FAILED, null, $outcome->errorClass, null, null, false);
        }
        $errorClass = $outcome->errorClass ?? ($outcome->status > 0 ? DeliveryStatus::ERROR_HTTP : DeliveryStatus::ERROR_CONNECT);
        $disable = $this->failingTooLong($endpointState['failing_since'], $now) ? self::DISABLE_FAILING : null;
        $circuitOpens = $endpointState['consecutive_failures'] + 1 >= self::CIRCUIT_THRESHOLD;
        if ($attempt >= self::MAX_ATTEMPTS || $this->windowEnded($createdAt, $now)) {
            $until = $circuitOpens ? $this->at($now, $this->delay($attempt)) : null;
            return new Decision(DeliveryStatus::FAILED, null, $errorClass, $disable, $until, false);
        }
        $next = $this->at($now, $this->retryDelay($outcome, $attempt, $now));
        return new Decision(DeliveryStatus::PENDING, $next, $errorClass, $disable, $circuitOpens ? $next : null, false);
    }

    /** Final decision for a row claimed after its window ended; null while it may still be sent. */
    public function expiry(string $createdAt, int $now): ?Decision
    {
        if (!$this->windowEnded($createdAt, $now)) {
            return null;
        }
        return new Decision(DeliveryStatus::FAILED, null, DeliveryStatus::ERROR_EXPIRED, null, null, false);
    }

    private function windowEnded(string $createdAt, int $now): bool
    {
        $created = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $createdAt, new \DateTimeZone('UTC'));
        return $created === false || $now >= $created->getTimestamp() + self::WINDOW;
    }

    private function failingTooLong(?string $failingSince, int $now): bool
    {
        if ($failingSince === null) {
            return false;
        }
        $since = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $failingSince, new \DateTimeZone('UTC'));
        return $since !== false && $now >= $since->getTimestamp() + self::WINDOW;
    }

    private function at(int $now, int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', $now + $seconds);
    }

    private function delay(int $attempt): int
    {
        $base = self::DELAYS[min(max($attempt, 1), count(self::DELAYS)) - 1];
        return (int)round($base * (1 + (2 * $this->random->next() - 1) * self::JITTER));
    }

    private function retryDelay(Outcome $outcome, int $attempt, int $now): int
    {
        $delay = $this->delay($attempt);
        $retryAfter = $this->retryAfter($outcome->retryAfter, $now);
        return $retryAfter === null ? $delay : max($delay, $retryAfter);
    }

    /** The Retry-After header as seconds from now, clamped to 6 h; null when unusable. */
    public function retryAfter(?string $header, int $now): ?int
    {
        $seconds = $this->retryAfterSeconds($header, $now);
        return $seconds === null ? null : min($seconds, self::MAX_RETRY_AFTER);
    }

    /** RFC 9110 delay-seconds or HTTP-date; null when unusable, past dates included. */
    private function retryAfterSeconds(?string $header, int $now): ?int
    {
        if ($header === null) {
            return null;
        }
        $header = trim($header);
        if (preg_match('/^\d+$/D', $header) === 1) {
            return strlen($header) > 9 ? self::MAX_RETRY_AFTER : (int)$header;
        }
        $date = \DateTimeImmutable::createFromFormat(self::HTTP_DATE, $header, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }
        $seconds = $date->getTimestamp() - $now;
        return $seconds > 0 ? $seconds : null;
    }
}
