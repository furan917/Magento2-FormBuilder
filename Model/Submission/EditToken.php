<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Model\Security\SigningKeys;

/**
 * Short-lived link token that lets one customer edit one submission of one form.
 */
class EditToken
{
    private const TTL = 3600;
    private const FUTURE_SKEW = 60;
    // Own derivation label, so a form token signature never verifies as an edit token.
    private const KEY_LABEL = 'furan_formbuilder_edit';

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly SigningKeys $keys,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @throws \RuntimeException when no crypt key is configured */
    public function issue(int $submissionId, int $customerId, int $formId): string
    {
        $timestamp = (int)($this->clock)();
        $key = $this->keys->newest(self::KEY_LABEL);

        return $timestamp . '.' . $submissionId . '.' . $this->sign($submissionId, $customerId, $formId, $timestamp, $key);
    }

    /** @return int|null the submission id the token names; null when it is invalid for this customer and form */
    public function verify(string $token, int $customerId, int $formId): ?int
    {
        if (!preg_match('/^(\d{1,12})\.(\d{1,12})\.([0-9a-f]{64})$/D', $token, $match)) {
            return null;
        }
        $timestamp = (int)$match[1];
        $submissionId = (int)$match[2];
        $age = (int)($this->clock)() - $timestamp;
        if ($age > self::TTL || $age < -self::FUTURE_SKEW || $submissionId < 1) {
            return null;
        }
        foreach ($this->keys->all(self::KEY_LABEL) as $key) {
            if (hash_equals($this->sign($submissionId, $customerId, $formId, $timestamp, $key), $match[3])) {
                return $submissionId;
            }
        }

        return null;
    }

    private function sign(int $submissionId, int $customerId, int $formId, int $timestamp, string $key): string
    {
        return hash_hmac('sha256', $submissionId . '|' . $customerId . '|' . $formId . '|' . $timestamp, $key);
    }
}
