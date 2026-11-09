<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\Data;

/**
 * @phpstan-type FormSchema array<string, mixed>
 * @phpstan-type FormSettings array<string, mixed>
 * @phpstan-type TranslationOverlay array<string, mixed>
 * @api
 */
interface FormInterface
{
    public const FORM_ID = 'form_id';
    public const IDENTIFIER = 'identifier';
    public const TITLE = 'title';
    public const IS_ACTIVE = 'is_active';
    public const SCHEMA_JSON = 'schema_json';
    public const SETTINGS_JSON = 'settings_json';
    public const STORE_IDS = 'store_ids';
    public const TRANSLATIONS = 'translations';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    /** @return int|string|null */
    public function getId();

    public function getIdentifier(): string;

    public function setIdentifier(string $identifier): self;

    public function getTitle(): string;

    public function setTitle(string $title): self;

    public function isActive(): bool;

    public function setIsActive(bool $isActive): self;

    /** @return FormSchema */
    public function getSchema(): array;

    /** @param FormSchema $schema */
    public function setSchema(array $schema): self;

    /** @return FormSettings */
    public function getSettings(): array;

    /** @param FormSettings $settings */
    public function setSettings(array $settings): self;

    /**
     * @return int[] 0 = all store views
     */
    public function getStoreIds(): array;

    /**
     * @param int[] $storeIds
     */
    public function setStoreIds(array $storeIds): self;

    /**
     * Store view overlays of the form's visitor-facing texts.
     *
     * @return array<int, TranslationOverlay> store id => overlay
     */
    public function getTranslations(): array;

    /**
     * @param array<int, TranslationOverlay> $translations
     * @return $this
     */
    public function setTranslations(array $translations): self;

    public function getCreatedAt(): ?string;

    public function getUpdatedAt(): ?string;
}
