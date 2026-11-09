<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model;

class Clock
{
    /** Unix time in seconds, with microseconds. */
    public function now(): float
    {
        return microtime(true);
    }
}
