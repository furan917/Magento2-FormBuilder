<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Model\AbstractModel;

class Form extends AbstractModel implements FormInterface, IdentityInterface
{
    use JsonColumns;

    public const CACHE_TAG = 'furan_form';

    /** @var string */
    protected $_cacheTag = self::CACHE_TAG;

    /** @var string */
    protected $_eventPrefix = 'furan_formbuilder_form';

    protected function _construct()
    {
        $this->_init(FormResource::class);
    }

    public function getIdentities(): array
    {
        $id = $this->getId();
        return [$id ? self::CACHE_TAG . '_' . $id : self::CACHE_TAG];
    }

    public function getIdentifier(): string
    {
        return (string)$this->getData(self::IDENTIFIER);
    }

    public function setIdentifier(string $identifier): FormInterface
    {
        return $this->setData(self::IDENTIFIER, $identifier);
    }

    public function getTitle(): string
    {
        return (string)$this->getData(self::TITLE);
    }

    public function setTitle(string $title): FormInterface
    {
        return $this->setData(self::TITLE, $title);
    }

    public function isActive(): bool
    {
        return (bool)$this->getData(self::IS_ACTIVE);
    }

    public function setIsActive(bool $isActive): FormInterface
    {
        return $this->setData(self::IS_ACTIVE, $isActive ? 1 : 0);
    }

    public function getSchema(): array
    {
        return $this->decodedColumn(self::SCHEMA_JSON) ?? [];
    }

    public function setSchema(array $schema): FormInterface
    {
        return $this->setData(self::SCHEMA_JSON, $this->encodeColumn($schema));
    }

    public function getSettings(): array
    {
        return $this->decodedColumn(self::SETTINGS_JSON) ?? [];
    }

    public function setSettings(array $settings): FormInterface
    {
        return $this->setData(self::SETTINGS_JSON, $this->encodeColumn($settings));
    }

    public function getStoreIds(): array
    {
        $ids = $this->getData(self::STORE_IDS);
        return is_array($ids) ? array_map('intval', array_values($ids)) : [];
    }

    public function setStoreIds(array $storeIds): FormInterface
    {
        return $this->setData(self::STORE_IDS, array_map('intval', array_values($storeIds)));
    }

    public function getTranslations(): array
    {
        $translations = $this->getData(self::TRANSLATIONS);
        return is_array($translations) ? $translations : [];
    }

    public function setTranslations(array $translations): FormInterface
    {
        return $this->setData(self::TRANSLATIONS, $translations);
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);
        return $value === null ? null : (string)$value;
    }

    public function getUpdatedAt(): ?string
    {
        $value = $this->getData(self::UPDATED_AT);
        return $value === null ? null : (string)$value;
    }
}
