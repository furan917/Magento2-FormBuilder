<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Backend\Model\Auth\Session;
use Magento\Framework\Encryption\EncryptorInterface;

/** Holds a new endpoint secret in the admin session, encrypted, until the next Edit page reads it once. */
class SecretStash
{
    private const KEY_PREFIX = 'ffb_webhook_secret_';

    public function __construct(private readonly Session $session, private readonly EncryptorInterface $encryptor)
    {
    }

    public function put(int $endpointId, string $plain): void
    {
        // setData() exists only through the session's __call to its storage.
        $this->session->__call('setData', [self::KEY_PREFIX . $endpointId, $this->encryptor->encrypt($plain)]);
    }

    /** Reads the secret and keeps it; null when there is none (or it cannot be decrypted). */
    public function peek(int $endpointId): ?string
    {
        return $this->decrypted($this->session->getData(self::KEY_PREFIX . $endpointId));
    }

    /** Reads and removes the secret; null when there is none (or it cannot be decrypted). */
    public function take(int $endpointId): ?string
    {
        return $this->decrypted($this->session->getData(self::KEY_PREFIX . $endpointId, true));
    }

    private function decrypted(mixed $stored): ?string
    {
        if (!is_string($stored) || $stored === '') {
            return null;
        }
        $plain = $this->encryptor->decrypt($stored);

        return $plain === '' ? null : $plain;
    }
}
