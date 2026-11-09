<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Upload;

use Magento\Framework\App\ResourceConnection;

/**
 * Tracks every stored upload so files without a submission can be removed by cron.
 */
class FileRegistry
{
    private const TABLE = 'furan_formbuilder_file';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function add(string $path): void
    {
        $this->resource->getConnection()->insert($this->table(), ['path' => $path]);
    }

    /**
     * Runs $save and links $paths to the id it returns in one transaction, so a saved submission never has
     * files the cleanup cron would treat as abandoned.
     *
     * @param string[] $paths
     * @param callable(): int $save
     */
    public function saveWith(array $paths, callable $save): void
    {
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $this->attach($paths, $save());
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /** @param string[] $paths */
    public function attach(array $paths, int $submissionId): void
    {
        if ($paths === []) {
            return;
        }
        $this->resource->getConnection()->update(
            $this->table(),
            ['submission_id' => $submissionId],
            ['path IN (?)' => $paths]
        );
    }

    /**
     * Unlinks files from a submission so the cleanup cron deletes them; remove() would leak them on disk.
     *
     * @param string[] $paths
     */
    public function detach(int $submissionId, array $paths): int
    {
        if ($paths === []) {
            return 0;
        }
        return (int)$this->resource->getConnection()->update(
            $this->table(),
            ['submission_id' => null],
            ['submission_id = ?' => $submissionId, 'path IN (?)' => $paths]
        );
    }

    public function remove(string $path): void
    {
        $this->resource->getConnection()->delete($this->table(), ['path = ?' => $path]);
    }

    /**
     * Files with no submission that were stored before $before, oldest first, after file id $after.
     *
     * @return array<int, string> file_id => path
     */
    public function orphans(string $before, int $after, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->table(), ['file_id', 'path'])
            ->where('submission_id IS NULL')
            ->where('created_at < ?', $before)
            ->where('file_id > ?', $after)
            ->order('file_id ASC')
            ->limit($limit);

        return array_map('strval', $connection->fetchPairs($select));
    }

    /** @param int[] $fileIds */
    public function forget(array $fileIds): void
    {
        if ($fileIds !== []) {
            $this->resource->getConnection()->delete($this->table(), ['file_id IN (?)' => $fileIds]);
        }
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
