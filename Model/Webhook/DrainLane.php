<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

/** One endpoint's position in a round-robin pass. */
class DrainLane
{
    /** @var array{0: string, 1: int}|null keyset cursor of the last row seen */
    public ?array $after = null;

    public bool $done = false;

    public function __construct(public readonly int $endpointId, public bool $cancelling)
    {
    }
}
