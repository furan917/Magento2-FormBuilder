<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Model\Webhook\SecretUnavailableException;
use Furan\FormBuilder\Model\Webhook\Signer;

/** Receiver-side verifier the webhook tests check the sender's signatures against. */
class SignatureVerifier
{
    private const TOLERANCE_SECONDS = 300;

    public function verify(
        string $secret,
        string $body,
        string $msgId,
        string $timestamp,
        string $signatureHeader,
        int $now
    ): bool {
        if (preg_match('/^\d{1,12}$/', $timestamp) !== 1 || abs($now - (int)$timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }
        try {
            $key = Signer::key($secret);
        } catch (SecretUnavailableException) {
            return false;
        }
        $expected = Signer::signature($key, $msgId . '.' . $timestamp . '.' . $body);
        $matched = false;
        foreach (explode(' ', $signatureHeader) as $candidate) {
            // No early exit: every candidate is compared.
            $matched = hash_equals($expected, $candidate) || $matched;
        }

        return $matched;
    }
}
