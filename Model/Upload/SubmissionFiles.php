<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Upload;

use Furan\FormBuilder\Model\FieldType\Pool;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class SubmissionFiles
{
    // Room for the text answers on top of the files when judging the request size.
    private const NON_FILE_BYTES = 1048576;

    public function __construct(
        private readonly FileStorage $fileStorage,
        private readonly FileValidator $fileValidator,
        private readonly UploadBudget $uploadBudget,
        private readonly Pool $fieldTypes,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * PHP has already buffered the body; this only avoids processing it.
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, array<string, mixed>> $files
     */
    public function isTooLarge(array $fields, int $contentLength, array $files): bool
    {
        $length = $contentLength;
        if ($length <= 0) {
            // Chunked bodies carry no Content-Length; judge the files PHP accepted instead.
            $length = $this->uploadedBytes($files);
            if ($length <= 0) {
                return false;
            }
        }
        $allowed = self::NON_FILE_BYTES;
        foreach ($fields as $field) {
            if ($this->fieldTypes->has((string)($field['type'] ?? '')) && $this->fieldTypes->get($field['type'])->isFile()) {
                $allowed += $this->fileValidator->maxBytes($field);
            }
        }
        return $length > $allowed;
    }

    /**
     * @param array<string, array<string, mixed>> $files
     */
    private function uploadedBytes(array $files): int
    {
        return array_sum(array_map(static fn ($upload): int => (int)($upload['size'] ?? 0), $files));
    }

    /**
     * @param array<string, array<string, mixed>> $files
     * @param list<array<string, mixed>> $fields
     * @param string[] $storedFiles collects stored paths so the caller can clean up on failure
     * @param array<string, string> $errors
     * @return array<string, array<string, mixed>>
     */
    public function process(
        array $files,
        array $fields,
        int $formId,
        bool $persist,
        array &$storedFiles,
        array &$errors
    ): array {
        $byName = [];
        foreach ($fields as $field) {
            $byName[$field['name']] = $field;
        }
        $files = array_intersect_key($files, $byName);
        foreach ($files as $name => $upload) {
            $error = $this->fileValidator->validate($upload, $byName[$name]);
            if ($error !== null) {
                $errors[$name] = $error;
            }
        }
        if ($errors !== []) {
            return [];
        }
        if ($persist && !$this->uploadBudget->reserve($formId, $this->uploadedBytes($files))) {
            foreach (array_keys($files) as $name) {
                $errors[$name] = (string)__('This form is receiving too many files right now. Please try again later.');
            }
            return [];
        }
        $meta = [];
        foreach ($files as $name => $upload) {
            try {
                $meta[$name] = $persist
                    ? $this->fileStorage->store($upload, $byName[$name], $formId)
                    : $this->describeUnstored($upload);
                if ($persist) {
                    $storedFiles[] = (string)$meta[$name]['file'];
                }
            } catch (FileSystemException $e) {
                $this->logger->error('Form upload could not be stored: ' . $e->getMessage(), ['exception' => $e]);
                $errors[$name] = (string)__('The file could not be uploaded.');
                return $meta;
            } catch (LocalizedException $e) {
                $errors[$name] = $e->getMessage();
                return $meta;
            }
        }
        return $meta;
    }

    /**
     * @param string[] $paths
     */
    public function delete(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                $this->fileStorage->delete($path);
            } catch (\Throwable $e) {
                $this->logger->error('Could not remove uploaded file: ' . $e->getMessage());
            }
        }
    }

    /**
     * Only called after the upload has been validated.
     *
     * @param array<string, mixed> $upload
     * @return array<string, mixed>
     */
    private function describeUnstored(array $upload): array
    {
        $tmp = (string)$upload['tmp_name'];

        return [
            'name' => $this->fileStorage->displayName((string)$upload['name']),
            'size' => (int)filesize($tmp),
            'mime' => $this->fileValidator->detectMime($tmp),
        ];
    }
}
