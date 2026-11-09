<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Upload;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Throttle\Counter;

/**
 * Caps the bytes one form may store per hour, so a botnet that passes the per-visitor limits still cannot
 * fill the disk or bucket.
 */
class UploadBudget
{
    private const WINDOW_SECONDS = 3600;

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly Counter $counter,
        private readonly Config $config,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** Counts the bytes against the form's budget; false when they would exceed it (they stay counted). */
    public function reserve(int $formId, int $bytes): bool
    {
        $budget = $this->config->getUploadBudgetBytes();
        if ($budget <= 0 || $bytes <= 0) {
            return true;
        }
        $key = 'ffb_ub_' . $formId . '|' . intdiv((int)($this->clock)(), self::WINDOW_SECONDS);

        return $this->counter->hit($key, self::WINDOW_SECONDS, $bytes) <= $budget;
    }
}
