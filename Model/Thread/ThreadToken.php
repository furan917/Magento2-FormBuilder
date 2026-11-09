<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Thread;

use Magento\Framework\App\DeploymentConfig;

/** Guest reply link "<expires>.<id>.<hmac>"; the MAC also binds the recipient fingerprint and revocation counter. */
class ThreadToken
{
    public const TTL = 30 * 86400;
    private const FUTURE_SKEW = 300;
    private const LABEL = 'furan_formbuilder_thread';

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @param string $fingerprint raw EmailHasher fingerprint of the recipient; null when no crypt key is configured */
    public function issue(int $submissionId, string $fingerprint, int $version): ?string
    {
        $keys = $this->keys();
        if ($submissionId < 1 || $keys === []) {
            return null;
        }
        $expires = (int)($this->clock)() + self::TTL;

        return $expires . '.' . $submissionId . '.'
            . $this->sign((string)end($keys), $submissionId, $fingerprint, $version, $expires);
    }

    /**
     * Shape and lifetime only; matches() proves the signature.
     *
     * @return array{id: int, expires: int, mac: string}|null
     */
    public function parse(string $token): ?array
    {
        if (preg_match('/^([1-9]\d{0,11})\.([1-9]\d{0,9})\.([0-9a-f]{64})$/D', $token, $match) !== 1) {
            return null;
        }
        $expires = (int)$match[1];
        $now = (int)($this->clock)();
        if ($expires < $now || $expires > $now + self::TTL + self::FUTURE_SKEW) {
            return null;
        }

        return ['id' => (int)$match[2], 'expires' => $expires, 'mac' => $match[3]];
    }

    /**
     * @param array{id: int, expires: int, mac: string} $parsed
     * @param array<int, string> $fingerprints recipient fingerprints for every key version (EmailHasher::candidates)
     */
    public function matches(array $parsed, array $fingerprints, int $version): bool
    {
        foreach ($this->keys() as $key) {
            foreach ($fingerprints as $fingerprint) {
                $expected = $this->sign($key, $parsed['id'], $fingerprint, $version, $parsed['expires']);
                if (hash_equals($expected, $parsed['mac'])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function sign(string $key, int $submissionId, string $fingerprint, int $version, int $expires): string
    {
        return hash_hmac(
            'sha256',
            $submissionId . '|' . bin2hex($fingerprint) . '|' . $version . '|' . $expires,
            hash_hmac('sha256', self::LABEL, $key)
        );
    }

    /** @return string[] every crypt key, oldest first */
    private function keys(): array
    {
        return preg_split('/\s+/', trim((string)$this->deploymentConfig->get('crypt/key')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
