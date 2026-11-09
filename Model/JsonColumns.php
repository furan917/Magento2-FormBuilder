<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model;

/**
 * JSON columns of a model, decoded once per stored string: setData() with a new string decodes again.
 */
trait JsonColumns
{
    /** @var array<string, array{string, array<mixed>|null}> column => [raw json, decoded] */
    private array $decodedColumns = [];

    /** @return array<mixed>|null null when empty, unreadable or not an object or list */
    private function decodedColumn(string $key): ?array
    {
        $json = $this->getData($key);
        if (!is_string($json) || $json === '') {
            return null;
        }
        if (($this->decodedColumns[$key][0] ?? null) !== $json) {
            $decoded = json_decode($json, true);
            $this->decodedColumns[$key] = [$json, is_array($decoded) ? $decoded : null];
        }
        return $this->decodedColumns[$key][1];
    }

    /** @param array<mixed> $data */
    private function encodeColumn(array $data): string
    {
        return json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }
}
