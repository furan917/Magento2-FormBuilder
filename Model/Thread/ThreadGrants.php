<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Thread;

use Magento\Customer\Model\Session;

/** Short-lived session permission to read and answer one guest thread, earned by redeeming a signed link. */
class ThreadGrants
{
    private const KEY = 'furan_formbuilder_thread_grants';
    private const TTL = 3600;
    private const MAX = 10;

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly Session $session,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @param string $fingerprint newest-key fingerprint of the recipient */
    public function grant(int $submissionId, int $version, string $fingerprint): void
    {
        $grants = $this->current();
        unset($grants[$submissionId]);
        $grants[$submissionId] = [
            'v' => $version,
            'f' => bin2hex($fingerprint),
            'e' => (int)($this->clock)() + self::TTL,
        ];
        $this->session->regenerateId();
        // SessionManager proxies setData() to its storage through __call.
        // @phpstan-ignore method.notFound
        $this->session->setData(self::KEY, array_slice($grants, -self::MAX, null, true));
    }

    /** @param array<int, string> $fingerprints the recipient's current fingerprints, every key version */
    public function allows(int $submissionId, int $version, array $fingerprints): bool
    {
        $grant = $this->current()[$submissionId] ?? null;
        if (!is_array($grant) || ($grant['v'] ?? null) !== $version || !is_string($grant['f'] ?? null)
            || (int)($grant['e'] ?? 0) < (int)($this->clock)()
        ) {
            return false;
        }
        foreach ($fingerprints as $fingerprint) {
            if (hash_equals($grant['f'], bin2hex($fingerprint))) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, mixed> */
    private function current(): array
    {
        $grants = $this->session->getData(self::KEY);
        return is_array($grants) ? $grants : [];
    }
}
