<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Schema;

use Furan\FormBuilder\Api\SchemaMigrationInterface;
use Psr\Log\LoggerInterface;

class SchemaMigrator
{
    /** @var array<int, SchemaMigrationInterface> */
    private readonly array $migrations;

    /**
     * @param SchemaMigrationInterface[] $migrations keyed by the version they read
     */
    public function __construct(
        LoggerInterface $logger,
        array $migrations = [],
        private readonly int $currentVersion = Normalizer::SCHEMA_VERSION
    ) {
        $valid = [];
        foreach ($migrations as $key => $migration) {
            if (!$migration instanceof SchemaMigrationInterface || $migration->fromVersion() !== (int)$key) {
                $logger->warning(sprintf('Schema migration pool item "%s" skipped: it does not match its version.', $key));
                continue;
            }
            $valid[(int)$key] = $migration;
        }
        $this->migrations = $valid;
    }

    /**
     * A missing or invalid version counts as 1.
     * @param array<string, mixed> $schema
     */
    public function versionOf(array $schema): int
    {
        return max(1, (int)($schema['version'] ?? 1));
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function isSupported(array $schema): bool
    {
        return $this->versionOf($schema) <= $this->currentVersion;
    }

    /**
     * @throws UnsupportedSchemaVersionException when the schema is newer than this module or a step is missing
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function upgrade(array $schema): array
    {
        $version = $this->versionOf($schema);
        if ($version === $this->currentVersion) {
            return $schema;
        }
        if ($version > $this->currentVersion) {
            throw new UnsupportedSchemaVersionException(
                $version,
                sprintf('The form schema version %d is newer than this module supports (%d).', $version, $this->currentVersion)
            );
        }
        for (; $version < $this->currentVersion; $version++) {
            if (!isset($this->migrations[$version])) {
                throw new UnsupportedSchemaVersionException(
                    $version,
                    sprintf('There is no migration from form schema version %d.', $version)
                );
            }
            $schema = $this->migrations[$version]->migrate($schema);
            $schema['version'] = $version + 1;
        }
        return $schema;
    }

    /**
     * For load paths: returns the upgraded JSON, or the input unchanged when it is current, newer or unreadable
     * (callers detect newer schemas with isSupported()). Never throws.
     */
    public function upgradeJson(string $json): string
    {
        if (str_starts_with($json, '{"version":' . $this->currentVersion . ',')) {
            return $json;
        }
        $schema = json_decode($json, true);
        if (!is_array($schema) || !$this->isSupported($schema) || $this->versionOf($schema) === $this->currentVersion) {
            return $json;
        }
        try {
            return (string)json_encode($this->upgrade($schema), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (UnsupportedSchemaVersionException) {
            return $json;
        }
    }
}
