<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Spam;

use Furan\FormBuilder\Model\Security\SigningKeys;
use Furan\FormBuilder\Model\Throttle\Counter;

class TokenService
{
    private const MAX_AGE = 21600;
    // Failed submits release the token so visitors can fix their answers; this caps it as a validation oracle.
    private const MAX_ATTEMPTS = 5;
    private const KEY_LABEL = 'furan_formbuilder_token';

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly SigningKeys $keys,
        private readonly Counter $counter,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @throws \RuntimeException when no crypt key is configured */
    public function issue(int $formId): string
    {
        $timestamp = (int)($this->clock)();
        $nonce = bin2hex(random_bytes(8));
        $key = $this->keys->newest(self::KEY_LABEL);

        return $timestamp . '.' . $nonce . '.' . $this->sign($formId, $timestamp, $nonce, $key);
    }

    public function verify(string $token, int $formId, int $minSeconds, int $maxAge = self::MAX_AGE): bool
    {
        $age = $this->signedAge($token, $formId);
        if ($age === null || $age < $minSeconds || $age > $maxAge) {
            return false;
        }

        return $this->counter->peek($this->consumedKey($token)) === 0
            && $this->counter->peek($this->attemptsKey($token)) < self::MAX_ATTEMPTS;
    }

    /** Age in seconds of a validly signed token of this form that is not older than the maximum age; null otherwise. */
    public function age(string $token, int $formId): ?int
    {
        $age = $this->signedAge($token, $formId);

        return $age !== null && $age <= self::MAX_AGE ? $age : null;
    }

    /** Seconds since issue for a well-formed, validly signed token of this form (not in the future); null otherwise. */
    private function signedAge(string $token, int $formId): ?int
    {
        if (!preg_match('/^(\d{1,12})\.([0-9a-f]{16})\.([0-9a-f]{64})$/D', $token, $match)) {
            return null;
        }
        $timestamp = (int)$match[1];
        $age = (int)($this->clock)() - $timestamp;
        if ($age < 0) {
            return null;
        }
        $signed = false;
        foreach ($this->keys->all(self::KEY_LABEL) as $key) {
            $signed = $signed || hash_equals($this->sign($formId, $timestamp, $match[2], $key), $match[3]);
        }

        return $signed ? $age : null;
    }

    /** Marks the token as in use; false when another request holds it, it was spent, or it ran out of attempts. */
    public function consume(string $token): bool
    {
        if ($this->counter->hit($this->attemptsKey($token), self::MAX_AGE) > self::MAX_ATTEMPTS) {
            return false;
        }

        return $this->counter->hit($this->consumedKey($token), self::MAX_AGE) === 1;
    }

    /** Frees a consumed token so the visitor can resubmit after a failed attempt; attempts stay counted. */
    public function release(string $token): void
    {
        $this->counter->clear($this->consumedKey($token));
    }

    private function consumedKey(string $token): string
    {
        return 'ffb_tok_' . hash('sha256', $token);
    }

    private function attemptsKey(string $token): string
    {
        return 'ffb_tka_' . hash('sha256', $token);
    }

    private function sign(int $formId, int $timestamp, string $nonce, string $key): string
    {
        return hash_hmac('sha256', $formId . '|' . $timestamp . '|' . $nonce, $key);
    }
}
