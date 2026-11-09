<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

/**
 * Optional capability: the field type decides its own CSV columns.
 *
 * @api
 */
interface ExportableTypeInterface
{
    /**
     * @param array<string, mixed> $field schema field
     * @return string[] one header per column
     */
    public function csvHeaders(array $field): array;

    /**
     * @param array<string, mixed> $field schema field
     * @param mixed $value stored answer
     * @return string[] same count and order as csvHeaders()
     */
    public function csvCells(array $field, mixed $value): array;

    /**
     * Columns whose cells skip the spreadsheet formula guard. The exporter still guards a cell unless it matches
     * the numeric coordinate pattern, so only validated numeric strings are ever left plain.
     *
     * @param array<string, mixed> $field schema field
     * @return int[] column indexes
     */
    public function csvPlainColumns(array $field): array;
}
