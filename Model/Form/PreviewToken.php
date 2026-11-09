<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Form;

use Furan\FormBuilder\Model\Security\SigningKeys;

/**
 * Short-lived signed token that lets an admin open an inactive form on the storefront.
 */
class PreviewToken
{
    private const TTL = 7200;
    // Own derivation label, so signatures never cross token types.
    private const KEY_LABEL = 'furan_formbuilder_preview';

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly SigningKeys $keys,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @throws \RuntimeException when no crypt key is configured */
    public function issue(int $formId): string
    {
        $expires = (int)($this->clock)() + self::TTL;

        return $expires . '.' . $this->sign($formId, $expires, $this->keys->newest(self::KEY_LABEL));
    }

    public function verify(string $token, int $formId): bool
    {
        if (!preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/D', $token, $match)) {
            return false;
        }
        $expires = (int)$match[1];
        $now = (int)($this->clock)();
        if ($expires < $now || $expires > $now + self::TTL) {
            return false;
        }
        foreach ($this->keys->all(self::KEY_LABEL) as $key) {
            if (hash_equals($this->sign($formId, $expires, $key), $match[2])) {
                return true;
            }
        }

        return false;
    }

    private function sign(int $formId, int $expires, string $key): string
    {
        return hash_hmac('sha256', $formId . '|' . $expires, $key);
    }
}
