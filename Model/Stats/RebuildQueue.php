<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Stats;

use Magento\Framework\App\ResourceConnection;

/** The "Rebuild now" queue: a flag on stat_state that the off-peak cron job consumes. */
class RebuildQueue
{
    private const UPSERT_FIELDS = ['rebuild_requested', 'rebuild_requested_at'];

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /** Queues one form, or every form flagged needs_rebuild when no id is given. Returns the forms queued. */
    public function request(?int $formId): int
    {
        $formIds = $formId !== null ? [$formId] : array_map('intval', $this->resource->getConnection()->fetchCol(
            'SELECT form_id FROM ' . $this->table() . ' WHERE needs_rebuild = 1'
        ));
        if ($formIds === []) {
            return 0;
        }
        $now = gmdate('Y-m-d H:i:s');
        $rows = array_map(
            static fn (int $id): array => ['form_id' => $id, 'rebuild_requested' => 1, 'rebuild_requested_at' => $now],
            $formIds
        );
        $this->resource->getConnection()->insertOnDuplicate($this->table(), $rows, self::UPSERT_FIELDS);
        return count($rows);
    }

    /** @return array<int, int> queued form ids, oldest request first */
    public function queued(): array
    {
        return array_map('intval', $this->resource->getConnection()->fetchCol(
            'SELECT form_id FROM ' . $this->table() . ' WHERE rebuild_requested = 1 ORDER BY rebuild_requested_at'
        ));
    }

    public function release(int $formId): void
    {
        $this->resource->getConnection()->update($this->table(), ['rebuild_requested' => 0], ['form_id = ?' => $formId]);
    }

    /** @return array{needs_rebuild: bool, rebuilding: bool, queued: bool, queued_at: ?string} */
    public function state(int $formId): array
    {
        $row = $this->resource->getConnection()->fetchRow(
            'SELECT needs_rebuild, rebuilding, rebuild_requested, rebuild_requested_at FROM '
            . $this->table() . ' WHERE form_id = ?',
            [$formId]
        ) ?: [];
        return [
            'needs_rebuild' => (int)($row['needs_rebuild'] ?? 0) === 1,
            'rebuilding' => (int)($row['rebuilding'] ?? 0) === 1,
            'queued' => (int)($row['rebuild_requested'] ?? 0) === 1,
            'queued_at' => $row['rebuild_requested_at'] ?? null,
        ];
    }

    private function table(): string
    {
        return $this->resource->getTableName('furan_formbuilder_stat_state');
    }
}
