<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

/** What one send attempt produced, reduced to what the delivery policy needs. */
class Outcome
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $errorClass,
        public readonly bool $bodyTruncated,
        public readonly ?string $retryAfter
    ) {
    }
}
