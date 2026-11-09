<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Magento\Framework\App\DeploymentConfig;

/**
 * Keyed email fingerprint. Version = position of the key in env.php crypt/key (never reorder it).
 */
class EmailHasher
{
    private const CONTEXT = 'furan_formbuilder_email';

    /** @var array<int, string>|null */
    private ?array $subKeys = null;

    public function __construct(private readonly DeploymentConfig $deploymentConfig)
    {
    }

    /**
     * Fingerprint with the newest key, for writes.
     *
     * @return array{hash: string, version: int}|null
     */
    public function hash(string $email): ?array
    {
        $normalized = $this->normalize($email);
        $subKeys = $this->subKeys();
        if ($normalized === '' || $subKeys === []) {
            return null;
        }
        $version = array_key_last($subKeys);

        return ['hash' => $this->fingerprint($normalized, $subKeys[$version]), 'version' => $version];
    }

    /**
     * One fingerprint per key version, for lookups.
     *
     * @return array<int, string>
     */
    public function candidates(string $email): array
    {
        $normalized = $this->normalize($email);
        if ($normalized === '') {
            return [];
        }
        return array_map(fn(string $subKey): string => $this->fingerprint($normalized, $subKey), $this->subKeys());
    }

    private function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function fingerprint(string $normalized, string $subKey): string
    {
        return hash_hmac('sha256', $normalized, $subKey, true);
    }

    /**
     * @return array<int, string>
     */
    private function subKeys(): array
    {
        if ($this->subKeys === null) {
            $keys = trim((string)$this->deploymentConfig->get('crypt/key'));
            $list = $keys === '' ? [] : (preg_split('/\s+/s', $keys) ?: []);
            $this->subKeys = array_map(
                static fn(string $key): string => hash_hmac('sha256', self::CONTEXT, $key, true),
                $list
            );
        }
        return $this->subKeys;
    }
}
