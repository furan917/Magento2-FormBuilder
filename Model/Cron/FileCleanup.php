<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Cron;

use Furan\FormBuilder\Model\Export\ExportConsumer;
use Furan\FormBuilder\Model\Upload\FileRegistry;
use Furan\FormBuilder\Model\Upload\FileStorage;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Removes uploads whose submission was deleted or never saved, and export files left by interrupted downloads.
 */
class FileCleanup
{
    // Long enough for a submission that is still being processed to link its files.
    private const GRACE_SECONDS = 3600;
    public const LOCK_NAME = 'furanform_file_cleanup';
    private const BATCH_SIZE = 500;
    // Under the 5-minute schedule, so runs never queue up behind each other.
    private const BUDGET_SECONDS = 240;
    private const EXPORT_DIR = 'export/furan_formbuilder';
    private const QUEUED_EXPORT_SECONDS = 86400;

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly FileRegistry $registry,
        private readonly FileStorage $storage,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger,
        private readonly LockManagerInterface $lockManager,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function execute(): void
    {
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return;
        }
        try {
            $this->removeOrphans();
            $this->removeStaleExports(self::EXPORT_DIR, self::GRACE_SECONDS);
            $this->removeStaleExports(ExportConsumer::DIRECTORY, self::QUEUED_EXPORT_SECONDS);
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    private function removeOrphans(): void
    {
        $deadline = ($this->clock)() + self::BUDGET_SECONDS;
        $before = gmdate('Y-m-d H:i:s', time() - self::GRACE_SECONDS);
        $removed = 0;
        $after = 0;
        while (($this->clock)() < $deadline) {
            $orphans = $this->registry->orphans($before, $after, self::BATCH_SIZE);
            if ($orphans === []) {
                break;
            }
            $done = [];
            foreach ($orphans as $fileId => $path) {
                $after = $fileId;
                try {
                    $this->storage->deleteFile($path);
                    $done[] = $fileId;
                } catch (\Throwable $e) {
                    $this->logger->error('Form Builder could not remove an upload: ' . $e->getMessage());
                }
            }
            $this->registry->forget($done);
            $removed += count($done);
        }
        if ($removed > 0) {
            $this->logger->info(sprintf('Form Builder removed %d unused upload(s).', $removed));
        }
    }

    private function removeStaleExports(string $dir, int $maxAge): void
    {
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        if (!$directory->isDirectory($dir)) {
            return;
        }
        $cutoff = time() - $maxAge;
        foreach ($directory->read($dir) as $path) {
            try {
                if ($directory->isFile($path) && (int)($directory->stat($path)['mtime'] ?? 0) < $cutoff) {
                    $directory->delete($path);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Form Builder could not remove an old export: ' . $e->getMessage());
            }
        }
    }
}
