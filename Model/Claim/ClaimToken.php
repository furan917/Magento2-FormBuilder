<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Claim;

use Furan\FormBuilder\Model\Security\SigningKeys;

/**
 * Expiring token that proves a mailbox owner may claim guest submissions made with one email fingerprint.
 */
class ClaimToken
{
    private const TTL = 7 * 86400;
    private const FUTURE_SKEW = 60;
    // Own derivation label, so signatures never cross token types.
    private const KEY_LABEL = 'furan_formbuilder_claim';

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly SigningKeys $keys,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * @param string $emailHash raw binary EmailHasher fingerprint
     * @throws \RuntimeException when no crypt key is configured
     */
    public function issue(int $customerId, string $emailHash): string
    {
        $timestamp = (int)($this->clock)();
        $key = $this->keys->newest(self::KEY_LABEL);

        return $timestamp . '.' . $customerId . '.' . $this->sign($customerId, $emailHash, $timestamp, $key);
    }

    /** @param array<int, string> $emailHashes raw fingerprints of the customer's current email, any key version */
    public function verify(string $token, int $customerId, array $emailHashes): bool
    {
        if (!preg_match('/^(\d{1,12})\.(\d{1,12})\.([0-9a-f]{64})$/D', $token, $match)
            || (int)$match[2] !== $customerId
        ) {
            return false;
        }
        $timestamp = (int)$match[1];
        $age = (int)($this->clock)() - $timestamp;
        if ($age > self::TTL || $age < -self::FUTURE_SKEW) {
            return false;
        }
        foreach ($this->keys->all(self::KEY_LABEL) as $key) {
            foreach ($emailHashes as $emailHash) {
                if (hash_equals($this->sign($customerId, $emailHash, $timestamp, $key), $match[3])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function sign(int $customerId, string $emailHash, int $timestamp, string $key): string
    {
        return hash_hmac('sha256', $customerId . '|' . bin2hex($emailHash) . '|' . $timestamp, $key);
    }
}
