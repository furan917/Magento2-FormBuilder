<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Api\Data\SubmissionInterface;

class SubmissionStub implements SubmissionInterface
{
    private ?int $formId = null;
    private ?int $storeId = null;
    private ?int $customerId = null;
    private array $payload = [];
    private string $summary = '';
    private ?string $ip = null;
    private bool $read = false;
    private ?string $createdAt = null;
    private int $status = 0;
    private int $customerGroupId = 0;
    private ?string $emailHash = null;
    private ?int $emailHashVersion = null;
    private ?array $context = null;
    public ?int $id = null;
    private string $reference = '';

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id === null ? null : (int)$id;
        return $this;
    }

    public function getFormId(): int
    {
        return (int)$this->formId;
    }

    public function setFormId(int $formId): SubmissionInterface
    {
        $this->formId = $formId;
        return $this;
    }

    public function getStoreId(): ?int
    {
        return $this->storeId;
    }

    public function setStoreId(?int $storeId): SubmissionInterface
    {
        $this->storeId = $storeId;
        return $this;
    }

    public function getCustomerId(): ?int
    {
        return $this->customerId;
    }

    public function setCustomerId(?int $customerId): SubmissionInterface
    {
        $this->customerId = $customerId;
        return $this;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function setPayload(array $payload): SubmissionInterface
    {
        $this->payload = $payload;
        return $this;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): SubmissionInterface
    {
        $this->summary = $summary;
        return $this;
    }

    public function getRemoteIp(): ?string
    {
        return $this->ip;
    }

    public function setRemoteIp(?string $remoteIp): SubmissionInterface
    {
        $this->ip = $remoteIp;
        return $this;
    }

    public function isRead(): bool
    {
        return $this->read;
    }

    public function setIsRead(bool $isRead): SubmissionInterface
    {
        $this->read = $isRead;
        return $this;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function setCreatedAt(string $utc): SubmissionInterface
    {
        $this->createdAt = $utc;
        return $this;
    }

    public function getUpdatedAt(): ?string
    {
        return null;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): SubmissionInterface
    {
        $this->status = $status;
        return $this;
    }

    public function getCustomerGroupId(): int
    {
        return $this->customerGroupId;
    }

    public function setCustomerGroupId(int $customerGroupId): SubmissionInterface
    {
        $this->customerGroupId = $customerGroupId;
        return $this;
    }

    public function getEmailHash(): ?string
    {
        return $this->emailHash;
    }

    public function setEmailHash(?string $emailHash): SubmissionInterface
    {
        $this->emailHash = $emailHash;
        return $this;
    }

    public function getEmailHashVersion(): ?int
    {
        return $this->emailHashVersion;
    }

    public function setEmailHashVersion(?int $version): SubmissionInterface
    {
        $this->emailHashVersion = $version;
        return $this;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }

    public function setContext(?array $context): SubmissionInterface
    {
        $this->context = $context;
        return $this;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): SubmissionInterface
    {
        $this->reference = $reference;
        return $this;
    }
}
