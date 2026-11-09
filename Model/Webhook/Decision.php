<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

/** The next step for a delivery; times are UTC `Y-m-d H:i:s`. */
class Decision
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $nextAttemptAt,
        public readonly ?string $errorClass,
        public readonly ?string $disableEndpoint,
        public readonly ?string $openCircuitUntil,
        public readonly bool $resetFailures
    ) {
    }
}
