<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

/** The serialised webhook body: signed and sent as exactly these bytes. */
class Envelope
{
    public function __construct(
        public readonly string $json,
        public readonly int $bytes
    ) {
    }
}
