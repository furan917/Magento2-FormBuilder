<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface;
use Furan\FormBuilder\Api\FieldType\DisplayableTypeInterface;
use Furan\FormBuilder\Api\FieldType\ExportableTypeInterface;
use Furan\FormBuilder\Model\Directory\CountryName;
use Furan\FormBuilder\Model\FieldType\Pool;
use Magento\Framework\Escaper;

/**
 * Flattens stored answers to plain text (callers escape) and CSV cells. Types that implement the
 * capability interfaces format their own values; everything else gets the generic scalar/list fallback.
 * $field is the current schema field, or ['name', 'label', 'type'] from the payload entry.
 */
class ValueFormatter
{
    public const COORDINATE = '/^-?\d{1,3}\.\d{6}$/D';

    public function __construct(private readonly Pool $fieldTypes)
    {
    }

    /**
     * @param array<string, mixed> $field
     */
    public function toText(array $field, mixed $value): string
    {
        $type = $this->displayable($field);
        return $type?->toText($field, $value) ?? self::flatten(self::withLabels($this->optionLabels($field), $value));
    }

    /**
     * Escaped HTML: the type's own markup, or the escaped plain text.
     *
     * @param array<string, mixed> $field
     */
    public function toHtml(array $field, mixed $value, Escaper $escaper): string
    {
        return $this->customHtml($field, $value, $escaper) ?? $escaper->escapeHtml(self::flatten($value));
    }

    /**
     * The type's own HTML, or null when the type does not implement DisplayableTypeInterface.
     *
     * @param array<string, mixed> $field
     */
    public function customHtml(array $field, mixed $value, Escaper $escaper): ?string
    {
        return $this->displayable($field)?->toHtml($field, $value, $escaper);
    }

    /**
     * @param array<string, mixed> $field
     */
    public function isDisplayable(array $field): bool
    {
        return $this->displayable($field) !== null;
    }

    /**
     * @param array<string, mixed> $field
     * @return string[]
     */
    public function csvHeaders(array $field): array
    {
        $type = $this->exportable($field);
        return $type?->csvHeaders($field) ?? [(string)($field['label'] ?? $field['name'] ?? '')];
    }

    /**
     * @param array<string, mixed> $field
     * @return string[] same count as csvHeaders()
     */
    public function csvCells(array $field, mixed $value): array
    {
        $type = $this->exportable($field);
        return $type?->csvCells($field, $value) ?? [self::csvCell($value)];
    }

    /**
     * @param array<string, mixed> $field
     * @return int[]
     */
    public function csvPlainColumns(array $field): array
    {
        return $this->exportable($field)?->csvPlainColumns($field) ?? [];
    }

    /**
     * Option value => label for a field whose answers are option values; empty for free text and pairs.
     *
     * @param array<string, mixed> $field
     * @return array<string, string>
     */
    public function optionLabels(array $field): array
    {
        $type = $this->typeOf($field);
        $kind = $type instanceof AdminViewTypeInterface ? $type->adminViewKind($field) : null;
        if (in_array($kind, [AdminViewTypeInterface::KIND_FREE_TEXT, AdminViewTypeInterface::KIND_PAIRS], true)) {
            return [];
        }
        $labels = [];
        foreach ((array)($field['options'] ?? []) as $option) {
            if (is_array($option) && isset($option['value'], $option['label'])) {
                $labels[(string)$option['value']] = (string)$option['label'];
            }
        }
        return $labels;
    }

    /**
     * The answer with each option value that still has a label replaced by it.
     *
     * @param array<string, string> $labels from optionLabels()
     */
    public static function withLabels(array $labels, mixed $value): mixed
    {
        if ($labels === []) {
            return $value;
        }
        if (is_array($value)) {
            return array_map(static fn ($item) => is_scalar($item) ? $labels[(string)$item] ?? $item : $item, $value);
        }
        return is_scalar($value) ? $labels[(string)$value] ?? $value : $value;
    }

    public static function fileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return max(1, (int)round($bytes / 1024)) . ' KB';
    }

    public function countryName(string $code): string
    {
        return CountryName::of($code);
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function hasCoordinates(array $value): bool
    {
        return isset($value['lat'], $value['lng']) && is_string($value['lat']) && is_string($value['lng'])
            && preg_match(self::COORDINATE, $value['lat']) === 1 && preg_match(self::COORDINATE, $value['lng']) === 1;
    }

    public static function flatten(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(
                static fn($item): string => is_scalar($item) ? (string)$item : '',
                $value
            ));
        }
        return is_scalar($value) ? (string)$value : '';
    }

    private static function csvCell(mixed $value): string
    {
        if (is_array($value) && (isset($value['file']) || isset($value['name']))) {
            return (string)($value['name'] ?? '');
        }
        return self::flatten($value);
    }

    /**
     * @param array<string, mixed> $field
     */
    private function displayable(array $field): ?DisplayableTypeInterface
    {
        $type = $this->typeOf($field);
        return $type instanceof DisplayableTypeInterface ? $type : null;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function exportable(array $field): ?ExportableTypeInterface
    {
        $type = $this->typeOf($field);
        return $type instanceof ExportableTypeInterface ? $type : null;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function typeOf(array $field): ?object
    {
        $code = (string)($field['type'] ?? '');
        return $this->fieldTypes->has($code) ? $this->fieldTypes->get($code) : null;
    }
}
