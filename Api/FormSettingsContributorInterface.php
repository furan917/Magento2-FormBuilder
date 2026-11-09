<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

/**
 * Adds form-level settings stored under `settings.ext.<code>`.
 *
 * Settings are plain JSON, visible to everyone with access to forms and included in builder and CLI exports:
 * never store secrets (API keys, tokens) here.
 *
 * @phpstan-import-type SettingSpec from FieldTypeInterface
 * @api
 */
interface FormSettingsContributorInterface
{
    /**
     * Namespace of the stored settings, matching ^[a-z][a-z0-9_]{0,31}$ (e.g. `acme_crm`); equals the di item name.
     */
    public function getCode(): string;

    /**
     * Title of the builder panel.
     */
    public function getLabel(): string;

    /**
     * Same shape as FieldTypeInterface::getSettingsSpec(), each entry additionally carries a `label`.
     *
     * @return array<string, SettingSpec>
     */
    public function getSpec(): array;

    /**
     * ACL resource an admin needs to change these settings; others keep the stored values and see the panel read-only.
     */
    public function getAclResource(): ?string;
}
