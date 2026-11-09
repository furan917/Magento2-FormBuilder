<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Cron;

use Furan\FormBuilder\Model\Cron\FileCleanup;
use Furan\FormBuilder\Model\Upload\FileRegistry;
use Furan\FormBuilder\Model\Upload\FileStorage;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileCleanupTest extends TestCase
{
    private function filesystem(array $exports = []): Filesystem
    {
        $directory = $this->createStub(WriteInterface::class);
        $directory->method('isDirectory')->willReturn($exports !== []);
        $directory->method('read')->willReturnCallback(static fn (string $dir): array => array_values(array_filter(
            array_keys($exports),
            static fn (string $p): bool => dirname($p) === $dir
        )));
        $directory->method('isFile')->willReturn(true);
        $directory->method('stat')->willReturnCallback(fn(string $p) => ['mtime' => $exports[$p]]);
        $directory->method('delete')->willReturnCallback(function (string $p) {
            $this->deletedExports[] = $p;
            return true;
        });
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->with(DirectoryList::VAR_DIR)->willReturn($directory);
        return $filesystem;
    }

    private array $deletedExports = [];

    private function locks(bool $free = true): LockManagerInterface
    {
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->with(FileCleanup::LOCK_NAME, 0)->willReturn($free);
        return $locks;
    }

    public function testItRemovesUnlinkedUploadsOlderThanAnHourPagingByFileId(): void
    {
        $registry = $this->createStub(FileRegistry::class);
        $calls = [];
        $registry->method('orphans')->willReturnCallback(function (string $before, int $after) use (&$calls) {
            $calls[] = [$before, $after];
            return match ($after) {
                0 => [3 => 'furan_formbuilder/1/2026/11/a', 4 => 'furan_formbuilder/1/2026/11/b'],
                4 => [9 => 'furan_formbuilder/1/2026/11/c'],
                default => [],
            };
        });
        $forgotten = [];
        $registry->method('forget')->willReturnCallback(function (array $ids) use (&$forgotten) {
            $forgotten[] = $ids;
        });
        $storage = $this->createStub(FileStorage::class);
        $storage->method('deleteFile')->willReturnCallback(function (string $path) {
            if (str_ends_with($path, '/b')) {
                throw new FileSystemException(__('Permission denied'));
            }
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $now = time();
        (new FileCleanup($registry, $storage, $this->filesystem(), $logger, $this->locks()))->execute();

        // A file that could not be removed keeps its row so the next run retries it.
        $this->assertSame([[3], [9]], $forgotten);
        $this->assertSame([0, 4, 9], array_column($calls, 1));
        $this->assertEqualsWithDelta($now - 3600, strtotime($calls[0][0] . ' UTC'), 5);
    }

    public function testItRemovesExportFilesLeftByInterruptedDownloads(): void
    {
        $registry = $this->createStub(FileRegistry::class);
        $registry->method('orphans')->willReturn([]);
        $old = time() - 7200;
        $fresh = time() - 60;
        (new FileCleanup(
            $registry,
            $this->createStub(FileStorage::class),
            $this->filesystem(['export/furan_formbuilder/old.csv' => $old, 'export/furan_formbuilder/new.csv' => $fresh]),
            $this->createStub(LoggerInterface::class),
            $this->locks()
        ))->execute();

        $this->assertSame(['export/furan_formbuilder/old.csv'], $this->deletedExports);
    }

    public function testItSkipsTheRunWhileAnotherNodeHoldsTheLock(): void
    {
        $registry = $this->createMock(FileRegistry::class);
        $registry->expects($this->never())->method('orphans');
        (new FileCleanup($registry, $this->createStub(FileStorage::class), $this->filesystem(),
            $this->createStub(LoggerInterface::class), $this->locks(false)))->execute();
    }

    public function testItStopsAtTheTimeBudget(): void
    {
        $registry = $this->createMock(FileRegistry::class);
        $registry->expects($this->exactly(2))->method('orphans')
            ->willReturnCallback(static fn (string $b, int $after): array => [$after + 1 => 'furan_formbuilder/x' . $after]);
        $calls = 0;
        (new FileCleanup($registry, $this->createStub(FileStorage::class), $this->filesystem(),
            $this->createStub(LoggerInterface::class), $this->locks(), static function () use (&$calls): float {
                return 100.0 * $calls++;
            }))->execute();
    }

    public function testItKeepsQueuedExportsForADayBecauseTheirLinkIsSentByNotification(): void
    {
        $registry = $this->createStub(FileRegistry::class);
        $registry->method('orphans')->willReturn([]);
        (new FileCleanup($registry, $this->createStub(FileStorage::class), $this->filesystem([
            'export/furan_formbuilder/queued/recent.csv' => time() - 7200,
            'export/furan_formbuilder/queued/old.csv' => time() - 90000,
        ]), $this->createStub(LoggerInterface::class), $this->locks()))->execute();

        $this->assertSame(['export/furan_formbuilder/queued/old.csv'], $this->deletedExports);
    }
}
