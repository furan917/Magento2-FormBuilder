<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

/** The columns of an active endpoint that decide whether an event goes to it. */
class EndpointRoute
{
    /**
     * @param int[] $scopeIds
     * @param int[] $formIds
     * @param string[] $events
     */
    public function __construct(
        private readonly int $id,
        private readonly string $scopeType,
        private readonly array $scopeIds,
        private readonly bool $allForms,
        private readonly array $formIds,
        private readonly array $events
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function scopeType(): string
    {
        return $this->scopeType;
    }

    /** @return int[] */
    public function scopeIds(): array
    {
        return $this->scopeIds;
    }

    public function allForms(): bool
    {
        return $this->allForms;
    }

    /** @return int[] */
    public function formIds(): array
    {
        return $this->formIds;
    }

    /** @return string[] */
    public function events(): array
    {
        return $this->events;
    }
}
