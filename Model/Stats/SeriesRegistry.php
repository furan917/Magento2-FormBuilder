<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Stats;

use Magento\Framework\App\ResourceConnection;

/** Loads a form's frozen series specs and creates the missing ones; an existing spec is never changed. */
class SeriesRegistry
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SeriesSpec $seriesSpec
    ) {
    }

    /**
     * Call outside the submit transaction: the insert must not hold its lock for the whole submit.
     *
     * @param list<array<string, mixed>> $fields schema field items
     * @return array<string, array{kind: int, bucket_step: ?float}> field_key => spec
     */
    public function specsFor(int $formId, array $fields): array
    {
        $specs = $this->load($formId);
        $missing = [];
        foreach ($fields as $field) {
            $name = (string)($field['name'] ?? '');
            if ($name !== '' && !isset($specs[$name]) && !isset($missing[$name])) {
                $missing[$name] = ['form_id' => $formId, 'field_key' => $name] + $this->seriesSpec->specFor($field);
            }
        }
        if ($missing === []) {
            return $specs;
        }
        // The no-op update keeps the first writer's spec when two first submits race.
        $this->resource->getConnection()->insertOnDuplicate($this->table(), array_values($missing), ['field_key']);

        return $this->load($formId);
    }

    /**
     * Rewrites stored specs in place (never delete + insert: the cascade would wipe live aggregates).
     * Fields without a stored spec are left to specsFor().
     *
     * @param list<array<string, mixed>> $fields schema field items
     * @return int specs changed
     */
    public function resetSpecs(int $formId, array $fields): int
    {
        $connection = $this->resource->getConnection();
        $stored = [];
        foreach ($connection->fetchAll(
            'SELECT field_id, field_key, kind, bucket_step FROM ' . $this->table() . ' WHERE form_id = ?',
            [$formId]
        ) as $row) {
            $stored[(string)$row['field_key']] = $row;
        }
        $changed = 0;
        foreach ($fields as $field) {
            $name = (string)($field['name'] ?? '');
            if ($name === '' || !isset($stored[$name])) {
                continue;
            }
            $spec = $this->seriesSpec->specFor($field);
            $row = $stored[$name];
            $step = $row['bucket_step'] === null ? null : (float)$row['bucket_step'];
            $newStep = $spec['bucket_step'] === null ? null : (float)$spec['bucket_step'];
            if ((int)$row['kind'] === $spec['kind'] && $step === $newStep) {
                continue;
            }
            $connection->update(
                $this->table(),
                ['kind' => $spec['kind'], 'bucket_step' => $spec['bucket_step']],
                ['field_id = ?' => (int)$row['field_id']]
            );
            $changed++;
        }
        return $changed;
    }

    /** @return array<string, array{kind: int, bucket_step: ?float}> */
    public function load(int $formId): array
    {
        $rows = $this->resource->getConnection()->fetchAll(
            'SELECT field_key, kind, bucket_step FROM ' . $this->table() . ' WHERE form_id = ?',
            [$formId]
        );
        $specs = [];
        foreach ($rows as $row) {
            $specs[(string)$row['field_key']] = [
                'kind' => (int)$row['kind'],
                'bucket_step' => $row['bucket_step'] === null ? null : (float)$row['bucket_step'],
            ];
        }
        return $specs;
    }

    private function table(): string
    {
        return $this->resource->getTableName('furan_formbuilder_stat_field');
    }
}
