<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

use Psr\Log\LoggerInterface;

/** Standard Webhooks v1 (HMAC-SHA256) signing. */
class Signer
{
    private const PREFIX = 'whsec_';
    private const MIN_KEY_BYTES = 24;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Signatures are newest first. $previousExpiresAt is a UTC Y-m-d H:i:s.
     *
     * @return array<string, string>
     * @throws SecretUnavailableException when the current secret is unusable
     */
    public function headers(
        string $msgId,
        int $timestamp,
        string $body,
        string $current,
        ?string $previous,
        ?string $previousExpiresAt,
        int $now,
        int $endpointId = 0
    ): array {
        $content = $msgId . '.' . $timestamp . '.' . $body;
        $signatures = [self::signature(self::key($current), $content)];

        if ($previous !== null && $this->windowOpen($previousExpiresAt, $now)) {
            try {
                $signatures[] = self::signature(self::key($previous), $content);
            } catch (SecretUnavailableException) {
                $this->logger->warning(
                    'Webhook previous secret is unusable; signing with the current secret only.',
                    ['endpoint_id' => $endpointId]
                );
            }
        }

        return [
            'webhook-id' => $msgId,
            'webhook-timestamp' => (string)$timestamp,
            'webhook-signature' => implode(' ', $signatures),
        ];
    }

    /** Raw HMAC key of a whsec_ secret; shared with the verifier. */
    public static function key(string $secret): string
    {
        if (!str_starts_with($secret, self::PREFIX)) {
            throw new SecretUnavailableException('Webhook secret is unusable.');
        }
        $key = base64_decode(substr($secret, strlen(self::PREFIX)), true);
        if ($key === false || strlen($key) < self::MIN_KEY_BYTES) {
            throw new SecretUnavailableException('Webhook secret is unusable.');
        }

        return $key;
    }

    /** @internal */
    public static function signature(string $key, string $content): string
    {
        return 'v1,' . base64_encode(hash_hmac('sha256', $content, $key, true));
    }

    private function windowOpen(?string $expiresAt, int $now): bool
    {
        if ($expiresAt === null) {
            return false;
        }
        $until = strtotime($expiresAt . ' UTC');

        return $until !== false && $until > $now;
    }
}
