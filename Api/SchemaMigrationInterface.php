<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

/**
 * Upgrades a stored form schema by one format version, registered in the `migrations` argument of
 * Model\Schema\SchemaMigrator under the key equal to fromVersion().
 * Keep every page and item id: translations are keyed by item id. The migrator sets `version` afterwards.
 *
 * @api
 */
interface SchemaMigrationInterface
{
    /** The schema version this migration reads; it produces fromVersion() + 1. */
    public function fromVersion(): int;

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function migrate(array $schema): array;
}
