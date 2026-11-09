<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Upload;

use Furan\FormBuilder\Model\Upload\FileRegistry;
use Furan\FormBuilder\Model\Upload\FileStorage;
use Furan\FormBuilder\Model\Upload\FileValidator;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\Driver\File as LocalDriver;
use Magento\Framework\Filesystem\File\WriteInterface as FileWriteInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class FileStorageTest extends TestCase
{
    private WriteInterface&Stub $directory;
    private FileValidator&Stub $validator;
    private FileRegistry&Stub $registry;
    private FileStorage $storage;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?WriteInterface $directory = null, ?FileRegistry $registry = null): void
    {
        $this->directory = $directory ?? $this->createStub(WriteInterface::class);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->with(DirectoryList::VAR_IMPORT_EXPORT)
            ->willReturn($this->directory);
        $this->validator = $this->createStub(FileValidator::class);
        $this->registry = $registry ?? $this->createStub(FileRegistry::class);
        $this->storage = new FileStorage($filesystem, $this->validator, $this->registry, new LocalDriver());
    }

    public function testItStreamsTheFileToARandomNameWithoutAnExtensionAndRegistersIt(): void
    {
        $registry = $this->createMock(FileRegistry::class);
        $this->fixture(registry: $registry);
        $tmp = (string)tempnam(sys_get_temp_dir(), 'ffb');
        file_put_contents($tmp, 'hello');
        $this->validator->method('validate')->willReturn(null);
        $this->validator->method('detectMime')->willReturn('text/plain');
        $written = '';
        $file = $this->createMock(FileWriteInterface::class);
        $file->method('write')->willReturnCallback(function (string $chunk) use (&$written) {
            $written .= $chunk;
            return strlen($chunk);
        });
        $file->expects(self::once())->method('close');
        $opened = null;
        $this->directory->method('openFile')->willReturnCallback(function (string $path) use (&$opened, $file) {
            $opened = $path;
            return $file;
        });
        $registered = null;
        $registry->expects(self::once())->method('add')->willReturnCallback(function (string $path) use (&$registered) {
            $registered = $path;
        });

        $meta = $this->storage->store(
            ['name' => "../evil\x01na\u{202E}me.txt", 'tmp_name' => $tmp, 'size' => 5, 'error' => 0],
            [],
            12
        );
        unlink($tmp);

        self::assertMatchesRegularExpression('#^furan_formbuilder/12/\d{4}/\d{2}/[a-f0-9]{32}$#D', $meta['file']);
        self::assertSame($meta['file'], $opened);
        self::assertSame($meta['file'], $registered);
        self::assertSame('hello', $written);
        self::assertSame('evilname.txt', $meta['name']);
        self::assertSame(5, $meta['size']);
        self::assertSame('text/plain', $meta['mime']);
    }

    public function testItThrowsWhenValidationFails(): void
    {
        $directory = $this->createMock(WriteInterface::class);
        $registry = $this->createMock(FileRegistry::class);
        $this->fixture(directory: $directory, registry: $registry);
        $this->validator->method('validate')->willReturn('This file type is not allowed.');
        $directory->expects(self::never())->method('openFile');
        $registry->expects(self::never())->method('add');
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This file type is not allowed.');
        $this->storage->store(['name' => 'a.txt', 'tmp_name' => '/x', 'size' => 1, 'error' => 0], [], 1);
    }

    public function testItRefusesToReadOrDeletePathsOutsideTheUploadPattern(): void
    {
        $directory = $this->createMock(WriteInterface::class);
        $this->fixture(directory: $directory);
        $directory->expects(self::never())->method('isFile');
        $directory->expects(self::never())->method('openFile');
        $directory->expects(self::never())->method('delete');
        foreach ([
            '../etc/passwd',
            'furan_formbuilder/../app/etc/env.php',
            'furan_formbuilder/1/2026/05/../../../x.txt',
            'other/1/2026/05/' . str_repeat('a', 32) . '.txt',
            'furan_formbuilder/1/2026/05/short.txt',
            '/furan_formbuilder/1/2026/05/' . str_repeat('a', 32) . '.txt',
        ] as $path) {
            try {
                $this->storage->downloadPath($path);
                self::fail('downloadPath accepted ' . $path);
            } catch (LocalizedException) {
                self::assertTrue(true);
            }
            try {
                $this->storage->copyToLocal($path, sys_get_temp_dir() . '/ffb-never');
                self::fail('copyToLocal accepted ' . $path);
            } catch (LocalizedException) {
                self::assertTrue(true);
            }
            try {
                $this->storage->delete($path);
                self::fail('delete accepted ' . $path);
            } catch (LocalizedException) {
                self::assertTrue(true);
            }
        }
    }

    public function testItReadsAndDeletesPathsMatchingTheUploadPattern(): void
    {
        $directory = $this->createMock(WriteInterface::class);
        $registry = $this->createMock(FileRegistry::class);
        $this->fixture(directory: $directory, registry: $registry);
        $path = 'furan_formbuilder/3/2026/05/' . str_repeat('a1', 16) . '.pdf';
        $directory->expects(self::never())->method('readFile');
        $directory->method('isFile')->with($path)->willReturn(true);
        $directory->method('isExist')->with($path)->willReturn(true);
        $directory->expects(self::once())->method('delete')->with($path);
        $registry->expects(self::once())->method('remove')->with($path);
        self::assertSame($path, $this->storage->downloadPath($path));
        self::assertSame(DirectoryList::VAR_IMPORT_EXPORT, FileStorage::DIRECTORY);
        $this->storage->delete($path);
    }

    public function testItRefusesToServeAStoredPathWhoseFileIsGone(): void
    {
        $directory = $this->createMock(WriteInterface::class);
        $this->fixture(directory: $directory);
        $path = 'furan_formbuilder/3/2026/05/' . str_repeat('a1', 16);
        $directory->method('isFile')->with($path)->willReturn(false);
        $this->expectException(LocalizedException::class);
        $this->storage->downloadPath($path);
    }

    public function testItCopiesAStoredFileToALocalPathInChunks(): void
    {
        $directory = $this->createMock(WriteInterface::class);
        $this->fixture(directory: $directory);
        $path = 'furan_formbuilder/3/2026/05/' . str_repeat('b2', 16);
        $chunks = ['abc', 'def'];
        $file = $this->createMock(\Magento\Framework\Filesystem\File\ReadInterface::class);
        $file->method('eof')->willReturnCallback(static function () use (&$chunks): bool {
            return $chunks === [];
        });
        $file->method('read')->willReturnCallback(static function () use (&$chunks): string {
            return (string)array_shift($chunks);
        });
        $file->expects(self::once())->method('close');
        $directory->method('openFile')->with($path, 'r')->willReturn($file);
        $directory->expects(self::never())->method('readFile');
        $target = (string)tempnam(sys_get_temp_dir(), 'ffbc');
        try {
            $this->storage->copyToLocal($path, $target);
            self::assertSame('abcdef', file_get_contents($target));
        } finally {
            unlink($target);
        }
    }

    public function testItDeletesTheWholeFormUploadDirectory(): void
    {
        $directory = $this->createMock(WriteInterface::class);
        $this->fixture(directory: $directory);
        $directory->method('isExist')->with('furan_formbuilder/7')->willReturn(true);
        $directory->expects(self::once())->method('delete')->with('furan_formbuilder/7');
        $this->storage->deleteFormFiles(7);
    }

    public function testItIgnoresInvalidFormIdsAndMissingDirectories(): void
    {
        $directory = $this->createMock(WriteInterface::class);
        $this->fixture(directory: $directory);
        $this->directory->method('isExist')->willReturn(false);
        $directory->expects(self::never())->method('delete');
        $this->storage->deleteFormFiles(0);
        $this->storage->deleteFormFiles(9);
    }
}
