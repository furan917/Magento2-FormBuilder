<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Magento\Framework\Encryption\EncryptorInterface;

class Secrets
{
    private const PREFIX = 'whsec_';

    /** @var callable */
    private $clock;

    public function __construct(private readonly EncryptorInterface $encryptor, ?callable $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    /** Standard Webhooks secret: whsec_ + base64 of 32 random bytes. */
    public function generate(): string
    {
        return self::PREFIX . base64_encode(random_bytes(32));
    }

    public function encrypt(string $plain): string
    {
        return $this->encryptor->encrypt($plain);
    }

    /** For the signer only. An undecryptable value comes back as '' and the signer must refuse it. */
    public function current(Endpoint $endpoint): string
    {
        return $this->encryptor->decrypt($endpoint->secretEnc());
    }

    /** The rotated-out secret while its window is open, else null. */
    public function previous(Endpoint $endpoint): ?string
    {
        $stored = $endpoint->previousSecretEnc();
        $expires = $endpoint->previousExpiresAt();
        if ($stored === null || $stored === '' || $expires === null) {
            return null;
        }
        $until = strtotime($expires . ' UTC');
        if ($until === false || $until <= (int)($this->clock)()) {
            return null;
        }

        return $this->encryptor->decrypt($stored);
    }
}
