<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Api\FieldType\DisplayableTypeInterface;
use Furan\FormBuilder\Api\FieldType\ExportableTypeInterface;
use Furan\FormBuilder\Model\FieldType\AbstractFieldType;
use Magento\Framework\Escaper;

/**
 * Custom structured type ({x, y}) with its own CSV columns and display; plain columns are configurable.
 */
class PointType extends AbstractFieldType implements ExportableTypeInterface, DisplayableTypeInterface
{
    /** @param int[] $plainColumns */
    public function __construct(private readonly array $plainColumns = [0])
    {
    }

    public function getCode(): string
    {
        return 'point';
    }

    public function getLabel(): string
    {
        return 'Point';
    }

    public function csvHeaders(array $field): array
    {
        return [$field['label'] . ' (x)', $field['label'] . ' (y)'];
    }

    public function csvCells(array $field, mixed $value): array
    {
        return [(string)($value['x'] ?? ''), (string)($value['y'] ?? '')];
    }

    public function csvPlainColumns(array $field): array
    {
        return $this->plainColumns;
    }

    public function toText(array $field, mixed $value): string
    {
        return 'P[' . ($value['x'] ?? '') . '|' . ($value['y'] ?? '') . ']';
    }

    public function toHtml(array $field, mixed $value, Escaper $escaper): string
    {
        return '<b>' . $escaper->escapeHtml($this->toText($field, $value)) . '</b>';
    }
}
