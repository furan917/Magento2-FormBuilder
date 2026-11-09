<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Security;

use Magento\Framework\App\DeploymentConfig;

/**
 * HMAC keys derived from every crypt/key version: sign with the newest, verify against all, so rotating
 * the key does not invalidate links already sent.
 */
class SigningKeys
{
    public function __construct(private readonly DeploymentConfig $deploymentConfig)
    {
    }

    /** @return string[] newest first; empty when no crypt key is configured */
    public function all(string $label): array
    {
        $keys = preg_split('/\s+/', trim((string)$this->deploymentConfig->get('crypt/key')), -1, PREG_SPLIT_NO_EMPTY);

        return array_map(
            static fn (string $key): string => hash_hmac('sha256', $label, $key),
            array_reverse($keys ?: [])
        );
    }

    /** @throws \RuntimeException when no crypt key is configured */
    public function newest(string $label): string
    {
        $keys = $this->all($label);
        if ($keys === []) {
            throw new \RuntimeException('No crypt/key is configured; refusing to sign tokens.');
        }

        return $keys[0];
    }
}
