<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Upload;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\Driver\File as LocalDriver;

class FileStorage
{
    public const DIRECTORY = DirectoryList::VAR_IMPORT_EXPORT;
    private const ROOT = 'furan_formbuilder';
    // Older uploads kept the visitor's extension; new ones have none so nothing on disk is ever executable.
    public const PATH_PATTERN = '#^furan_formbuilder/\d+/\d{4}/\d{2}/[a-f0-9]{32}(\.[a-z0-9]{1,8})?$#D';
    private const MAX_NAME_LENGTH = 255;
    private const CHUNK_BYTES = 1048576;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly FileValidator $validator,
        private readonly FileRegistry $registry,
        private readonly LocalDriver $localDriver
    ) {
    }

    /**
     * @return array{file: string, name: string, size: int, mime: string}
     * @throws LocalizedException
     * @param array<string, mixed> $upload
     * @param array<string, mixed> $field
     */
    public function store(array $upload, array $field, int $formId): array
    {
        $error = $this->validator->validate($upload, $field);
        if ($error !== null) {
            throw new LocalizedException(__($error));
        }

        $tmp = (string)$upload['tmp_name'];
        $relative = self::ROOT . '/' . $formId . '/' . date('Y/m') . '/' . bin2hex(random_bytes(16));
        // Registered before writing so a request that dies mid-write still leaves a row for the cleanup cron.
        $this->registry->add($relative);
        $size = $this->copy($tmp, $relative);

        return [
            'file' => $relative,
            'name' => $this->displayName((string)$upload['name']),
            'size' => $size,
            'mime' => $this->validator->detectMime($tmp),
        ];
    }

    /**
     * A vetted path inside DIRECTORY, for streaming with FileFactory instead of loading the file into memory.
     *
     * @throws LocalizedException
     */
    public function downloadPath(string $relative): string
    {
        $this->assertStoredPath($relative);
        if (!$this->directory()->isFile($relative)) {
            throw new LocalizedException(__('The requested file does not exist.'));
        }

        return $relative;
    }

    /**
     * Copies through the directory API, so remote storage works and nothing outside the upload root is read.
     *
     * @throws LocalizedException
     */
    public function copyToLocal(string $relative, string $localPath): void
    {
        $this->assertStoredPath($relative);
        $in = $this->directory()->openFile($relative, 'r');
        $out = $this->localDriver->fileOpen($localPath, 'wb');
        try {
            while (!$in->eof()) {
                $this->localDriver->fileWrite($out, (string)$in->read(self::CHUNK_BYTES));
            }
        } finally {
            $in->close();
            $this->localDriver->fileClose($out);
        }
    }

    /** @throws LocalizedException */
    public function delete(string $relative): void
    {
        $this->deleteFile($relative);
        $this->registry->remove($relative);
    }

    /** Removes the file only; a file that is already gone is not an error. */
    public function deleteFile(string $relative): void
    {
        $this->assertStoredPath($relative);
        if ($this->directory()->isExist($relative)) {
            $this->directory()->delete($relative);
        }
    }

    public function deleteFormFiles(int $formId): void
    {
        if ($formId <= 0) {
            return;
        }
        $path = self::ROOT . '/' . $formId;
        if ($this->directory()->isExist($path)) {
            $this->directory()->delete($path);
        }
    }

    private function assertStoredPath(string $relative): void
    {
        if (preg_match(self::PATH_PATTERN, $relative) !== 1) {
            throw new LocalizedException(__('Invalid file path.'));
        }
    }

    private function copy(string $tmp, string $relative): int
    {
        $out = $this->directory()->openFile($relative, 'w');
        $in = $this->localDriver->fileOpen($tmp, 'rb');
        $size = 0;
        try {
            while (!$this->localDriver->endOfFile($in)) {
                $chunk = $this->localDriver->fileRead($in, self::CHUNK_BYTES);
                $size += strlen($chunk);
                $out->write($chunk);
            }
        } finally {
            $this->localDriver->fileClose($in);
            $out->close();
        }
        return $size;
    }

    public function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        // \p{Cf} covers bidi overrides that can make "cv\u{202E}fdp.exe" display as "cvexe.pdf".
        $name = (string)preg_replace('/[\x00-\x1f\x7f\p{Cf}]/u', '', $name);

        return mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }

    private function directory(): WriteInterface
    {
        return $this->filesystem->getDirectoryWrite(DirectoryList::VAR_IMPORT_EXPORT);
    }
}
