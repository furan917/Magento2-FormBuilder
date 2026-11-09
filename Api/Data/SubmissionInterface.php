<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\Data;

/**
 * @phpstan-type PayloadEntry array{name: string, label: string, type: string, value: mixed}
 * @phpstan-type ProductContext array{id?: int, sku?: string, name?: string, url?: string, price?: float, currency?: string}
 * @api
 */
interface SubmissionInterface
{
    public const SUBMISSION_ID = 'submission_id';
    public const FORM_ID = 'form_id';
    public const STORE_ID = 'store_id';
    public const CUSTOMER_ID = 'customer_id';
    public const PAYLOAD_JSON = 'payload_json';
    public const SUMMARY = 'summary';
    public const REMOTE_IP = 'remote_ip';
    public const IS_READ = 'is_read';
    public const CREATED_AT = 'created_at';
    public const CUSTOMER_GROUP_ID = 'customer_group_id';
    public const EMAIL_HASH = 'email_hash';
    public const EMAIL_HASH_VERSION = 'email_hash_version';
    public const STATUS = 'status';
    public const UPDATED_AT = 'updated_at';
    public const CONTEXT_JSON = 'context_json';
    public const REFERENCE = 'reference';

    public const STATUS_NEW = 0;
    public const STATUS_IN_PROGRESS = 1;
    public const STATUS_ANSWERED = 2;
    public const STATUS_CLOSED = 3;

    /** @return int|string|null */
    public function getId();

    /**
     * @param int|string|null $id
     * @return $this
     */
    public function setId($id);

    public function getFormId(): int;

    public function setFormId(int $formId): self;

    public function getStoreId(): ?int;

    public function setStoreId(?int $storeId): self;

    public function getCustomerId(): ?int;

    public function setCustomerId(?int $customerId): self;

    /**
     * @return array<int, PayloadEntry>
     */
    public function getPayload(): array;

    /** @param array<int, PayloadEntry> $payload */
    public function setPayload(array $payload): self;

    public function getSummary(): string;

    public function setSummary(string $summary): self;

    public function getRemoteIp(): ?string;

    public function setRemoteIp(?string $remoteIp): self;

    public function isRead(): bool;

    public function setIsRead(bool $isRead): self;

    public function getCreatedAt(): ?string;

    /**
     * @param string $utc UTC 'Y-m-d H:i:s'
     */
    public function setCreatedAt(string $utc): self;

    public function getUpdatedAt(): ?string;

    public function getStatus(): int;

    public function setStatus(int $status): self;

    public function getCustomerGroupId(): int;

    public function setCustomerGroupId(int $customerGroupId): self;

    public function getEmailHash(): ?string;

    public function setEmailHash(?string $emailHash): self;

    public function getEmailHashVersion(): ?int;

    public function setEmailHashVersion(?int $version): self;

    /**
     * @return ProductContext|null
     */
    public function getContext(): ?array;

    /** @param ProductContext|null $context */
    public function setContext(?array $context): self;

    /** UUIDv7 in 8-4-4-4-12 lower-case hex; '' until one is assigned. */
    public function getReference(): string;

    /**
     * @param string $reference any hyphenation and case
     * @throws \InvalidArgumentException when it is not a 32 digit hex UUID
     */
    public function setReference(string $reference): self;
}
