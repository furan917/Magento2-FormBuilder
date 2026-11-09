<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Webhook;

/** Read-only endpoint settings. Deliberately has no secret, so it can go to events, grids and templates. */
class EndpointView
{
    /**
     * @param int[] $scopeIds
     * @param int[] $formIds
     * @param string[] $events
     * @param string[] $fields
     */
    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly string $url,
        private readonly bool $active,
        private readonly string $scopeType,
        private readonly array $scopeIds,
        private readonly bool $allForms,
        private readonly array $formIds,
        private readonly array $events,
        private readonly string $fieldMode,
        private readonly array $fields,
        private readonly bool $includePersonal,
        private readonly bool $debugResponses,
        private readonly ?string $previousExpiresAt,
        private readonly ?string $disabledReason,
        private readonly ?string $failingSince,
        private readonly int $consecutiveFailures,
        private readonly ?string $circuitOpenUntil
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function isActive(): bool
    {
        return $this->active;
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

    public function fieldMode(): string
    {
        return $this->fieldMode;
    }

    /** @return string[] */
    public function fields(): array
    {
        return $this->fields;
    }

    public function includePersonal(): bool
    {
        return $this->includePersonal;
    }

    public function debugResponses(): bool
    {
        return $this->debugResponses;
    }

    public function previousExpiresAt(): ?string
    {
        return $this->previousExpiresAt;
    }

    public function disabledReason(): ?string
    {
        return $this->disabledReason;
    }

    public function failingSince(): ?string
    {
        return $this->failingSince;
    }

    public function consecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function circuitOpenUntil(): ?string
    {
        return $this->circuitOpenUntil;
    }
}
