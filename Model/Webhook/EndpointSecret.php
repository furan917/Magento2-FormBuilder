<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

/** The plain secret of an endpoint, handed out once by create() and rotate(); never stored in this form. */
class EndpointSecret
{
    public function __construct(private readonly int $endpointId, private readonly string $plain)
    {
    }

    public function endpointId(): int
    {
        return $this->endpointId;
    }

    public function plain(): string
    {
        return $this->plain;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['endpointId' => $this->endpointId];
    }
}
