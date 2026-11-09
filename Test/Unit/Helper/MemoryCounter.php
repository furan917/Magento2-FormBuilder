<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Model\Throttle\Counter;

/**
 * In-memory Counter with the same expiry semantics as the SQL one.
 */
class MemoryCounter extends Counter
{
    /** @var array<string, array{0: int, 1: int}> key => [hits, expires_at] */
    public array $rows = [];

    /** @var callable */
    private $now;

    public function __construct(?callable $clock = null)
    {
        $this->now = $clock ?? time(...);
    }

    public function hit(string $key, int $ttl, int $by = 1): int
    {
        $now = (int)($this->now)();
        [$hits, $expires] = $this->rows[$key] ?? [0, 0];
        $this->rows[$key] = $expires <= $now ? [$by, $now + max(1, $ttl)] : [$hits + $by, $expires];

        return $this->rows[$key][0];
    }

    public function peek(string $key): int
    {
        [$hits, $expires] = $this->rows[$key] ?? [0, 0];

        return $expires > (int)($this->now)() ? $hits : 0;
    }

    public function clear(string $key): void
    {
        unset($this->rows[$key]);
    }
}
