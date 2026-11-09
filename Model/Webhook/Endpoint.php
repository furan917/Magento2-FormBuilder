<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

/** The encrypted secret columns of one endpoint. Only the signer reads them, through Secrets. */
class Endpoint
{
    public function __construct(
        private readonly int $id,
        private readonly string $secretEnc,
        private readonly ?string $previousSecretEnc,
        private readonly ?string $previousExpiresAt
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function secretEnc(): string
    {
        return $this->secretEnc;
    }

    public function previousSecretEnc(): ?string
    {
        return $this->previousSecretEnc;
    }

    public function previousExpiresAt(): ?string
    {
        return $this->previousExpiresAt;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['id' => $this->id];
    }
}
