<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\ResourceModel\Submission as SubmissionResource;
use Furan\FormBuilder\Model\Submission\Reference;
use Magento\Framework\Model\AbstractModel;

class Submission extends AbstractModel implements SubmissionInterface
{
    use JsonColumns;

    protected $_eventPrefix = 'furan_formbuilder_submission';
    protected $_eventObject = 'submission';

    protected function _construct()
    {
        $this->_init(SubmissionResource::class);
    }

    public function getFormId(): int
    {
        return (int)$this->getData(self::FORM_ID);
    }

    public function setFormId(int $formId): SubmissionInterface
    {
        return $this->setData(self::FORM_ID, $formId);
    }

    public function getStoreId(): ?int
    {
        return $this->getNullableInt(self::STORE_ID);
    }

    public function setStoreId(?int $storeId): SubmissionInterface
    {
        return $this->setData(self::STORE_ID, $storeId);
    }

    public function getCustomerId(): ?int
    {
        return $this->getNullableInt(self::CUSTOMER_ID);
    }

    public function setCustomerId(?int $customerId): SubmissionInterface
    {
        return $this->setData(self::CUSTOMER_ID, $customerId);
    }

    public function getPayload(): array
    {
        return $this->decodedColumn(self::PAYLOAD_JSON) ?? [];
    }

    public function setPayload(array $payload): SubmissionInterface
    {
        return $this->setData(self::PAYLOAD_JSON, $this->encodeColumn($payload));
    }

    public function getSummary(): string
    {
        return (string)$this->getData(self::SUMMARY);
    }

    public function setSummary(string $summary): SubmissionInterface
    {
        return $this->setData(self::SUMMARY, $summary);
    }

    public function getRemoteIp(): ?string
    {
        $ip = $this->getData(self::REMOTE_IP);
        return $ip === null ? null : (string)$ip;
    }

    public function setRemoteIp(?string $remoteIp): SubmissionInterface
    {
        return $this->setData(self::REMOTE_IP, $remoteIp);
    }

    public function isRead(): bool
    {
        return (bool)$this->getData(self::IS_READ);
    }

    public function setIsRead(bool $isRead): SubmissionInterface
    {
        return $this->setData(self::IS_READ, $isRead ? 1 : 0);
    }

    public function getCreatedAt(): ?string
    {
        $createdAt = $this->getData(self::CREATED_AT);
        return $createdAt === null ? null : (string)$createdAt;
    }

    public function setCreatedAt(string $utc): SubmissionInterface
    {
        return $this->setData(self::CREATED_AT, $utc);
    }

    public function getUpdatedAt(): ?string
    {
        $updatedAt = $this->getData(self::UPDATED_AT);
        return $updatedAt === null ? null : (string)$updatedAt;
    }

    public function getStatus(): int
    {
        return (int)$this->getData(self::STATUS);
    }

    public function setStatus(int $status): SubmissionInterface
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getCustomerGroupId(): int
    {
        return (int)$this->getData(self::CUSTOMER_GROUP_ID);
    }

    public function setCustomerGroupId(int $customerGroupId): SubmissionInterface
    {
        return $this->setData(self::CUSTOMER_GROUP_ID, $customerGroupId);
    }

    public function getEmailHash(): ?string
    {
        $hash = $this->getData(self::EMAIL_HASH);
        return $hash === null ? null : (string)$hash;
    }

    public function setEmailHash(?string $emailHash): SubmissionInterface
    {
        return $this->setData(self::EMAIL_HASH, $emailHash);
    }

    public function getEmailHashVersion(): ?int
    {
        return $this->getNullableInt(self::EMAIL_HASH_VERSION);
    }

    public function setEmailHashVersion(?int $version): SubmissionInterface
    {
        return $this->setData(self::EMAIL_HASH_VERSION, $version);
    }

    public function getContext(): ?array
    {
        return $this->decodedColumn(self::CONTEXT_JSON);
    }

    public function setContext(?array $context): SubmissionInterface
    {
        return $this->setData(self::CONTEXT_JSON, $context === null ? null : $this->encodeColumn($context));
    }

    public function getReference(): string
    {
        $bytes = $this->getData(self::REFERENCE);

        return is_string($bytes) ? Reference::toString($bytes) : '';
    }

    public function setReference(string $reference): SubmissionInterface
    {
        $bytes = Reference::parse($reference);
        if ($bytes === null) {
            throw new \InvalidArgumentException('The submission reference is not a valid UUID.');
        }

        return $this->setData(self::REFERENCE, $bytes);
    }

    private function getNullableInt(string $key): ?int
    {
        $value = $this->getData($key);
        return $value === null || $value === '' ? null : (int)$value;
    }
}
