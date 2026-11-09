<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

/** Injectable random source so jitter is deterministic in tests. */
class RandomFraction
{
    private const RESOLUTION = 1000000;

    /** Uniform in [0, 1]. */
    public function next(): float
    {
        return random_int(0, self::RESOLUTION) / self::RESOLUTION;
    }
}
