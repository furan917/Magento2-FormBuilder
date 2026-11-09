<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Upload;

class FilesNormalizer
{
    private const KEYS = ['name', 'type', 'tmp_name', 'error', 'size'];

    /**
     * @return array<string, array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
    public function normalize(mixed $files): array
    {
        if (is_object($files) && method_exists($files, 'toArray')) {
            $files = $files->toArray();
        }
        if (!is_array($files)) {
            return [];
        }
        if ($this->isTransposed($files)) {
            $files = $this->untranspose($files);
        }

        $result = [];
        foreach ($files as $field => $upload) {
            if (!is_string($field) || !is_array($upload) || !$this->isScalarUpload($upload)) {
                continue;
            }
            $result[$field] = [
                'name' => (string)($upload['name'] ?? ''),
                'type' => (string)($upload['type'] ?? ''),
                'tmp_name' => (string)$upload['tmp_name'],
                'error' => (int)$upload['error'],
                'size' => (int)($upload['size'] ?? 0),
            ];
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $files
     */
    private function isTransposed(array $files): bool
    {
        return isset($files['tmp_name'], $files['error']) && is_array($files['tmp_name'])
            && is_array($files['error']) && array_filter(array_keys($files['tmp_name']), is_string(...)) !== [];
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, array<string, mixed>>
     */
    private function untranspose(array $files): array
    {
        $result = [];
        foreach (array_keys($files['tmp_name']) as $field) {
            foreach (self::KEYS as $key) {
                if (isset($files[$key]) && is_array($files[$key]) && array_key_exists($field, $files[$key])) {
                    $result[$field][$key] = $files[$key][$field];
                }
            }
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $upload
     */
    private function isScalarUpload(array $upload): bool
    {
        if (!isset($upload['tmp_name'], $upload['error'])) {
            return false;
        }
        foreach (self::KEYS as $key) {
            if (isset($upload[$key]) && !is_scalar($upload[$key])) {
                return false;
            }
        }
        return true;
    }
}
