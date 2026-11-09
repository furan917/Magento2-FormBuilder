<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface;
use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Api\FieldType\ClientMessagesInterface;
use Furan\FormBuilder\Api\FieldType\ConditionSourceInterface;

/**
 * Likert grid: options are the columns, settings.rows the rows. Posted as name[<row index>]=<column value>
 * and stored as "<row label>: <column label>" strings.
 */
class Matrix extends AbstractFieldType implements ClientMessagesInterface, ConditionSourceInterface, StatsAwareTypeInterface, AdminViewTypeInterface
{
    /** Stands in for a posted entry that names no valid row or column, so validateValue can reject it. */
    public const INVALID_ENTRY = "\0";

    public function getCode(): string
    {
        return 'matrix';
    }

    public function canBeConditionSource(): bool
    {
        return false;
    }

    public function getLabel(): string
    {
        return (string)__('Matrix');
    }

    public function getCategory(): string
    {
        return 'choice';
    }

    public function hasOptions(): bool
    {
        return true;
    }

    public function isMultiValue(): bool
    {
        return true;
    }

    public function getSettingsSpec(): array
    {
        return [
            'rows' => ['type' => 'list', 'translatable' => true, 'default' => ['Row 1', 'Row 2'], 'min_items' => 1, 'max_items' => 20, 'multiline' => true],
            'require_all' => ['type' => 'bool', 'default' => true],
        ];
    }

    public function normalizeValue(mixed $raw, array $field): string|array|null
    {
        if (!is_array($raw)) {
            return null;
        }
        $rows = $this->getRows($field);
        $columns = $this->getColumns($field);
        $answers = [];
        $invalid = false;
        foreach ($raw as $index => $item) {
            $column = is_scalar($item) ? parent::normalizeValue($item, $field) : null;
            if ($column === '') {
                continue;
            }
            if (!is_int($index) || !isset($rows[$index]) || !is_string($column) || !isset($columns[$column])) {
                $invalid = true;
                continue;
            }
            $answers[$index] = $rows[$index] . ': ' . $columns[$column];
        }
        if ($invalid) {
            return [self::INVALID_ENTRY];
        }
        ksort($answers);
        return array_values($answers);
    }

    public function validateValue(string|array $value, array $field, array $context): ?string
    {
        if (!is_array($value) || in_array(self::INVALID_ENTRY, $value, true)) {
            return $this->getInvalidMessage($field);
        }
        if (!empty($field['required']) && ($field['settings']['require_all'] ?? true)
            && count($value) < count($this->getRows($field))
        ) {
            return $this->getIncompleteMessage();
        }
        return null;
    }

    /**
     * @param array<string, mixed> $field
     */
    public function getInvalidMessage(array $field): ?string
    {
        return (string)__('Please choose a valid option.');
    }

    public function getIncompleteMessage(): string
    {
        return (string)__('Please answer every row.');
    }

    public function getClientMessages(array $field): array
    {
        return ['rows_message' => $this->getIncompleteMessage()];
    }

    /**
     * @param array<string, mixed> $field
     * @return string[]
     */
    private function getRows(array $field): array
    {
        return array_values(array_map('strval', (array)($field['settings']['rows'] ?? [])));
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, string> column value => label
     */
    private function getColumns(array $field): array
    {
        $columns = [];
        foreach ($field['options'] ?? [] as $option) {
            $columns[(string)($option['value'] ?? '')] = (string)($option['label'] ?? '');
        }
        return $columns;
    }

    public function statsKind(array $field): ?int
    {
        return self::KIND_MATRIX;
    }

    public function adminViewKind(array $field): ?string
    {
        return self::KIND_PAIRS;
    }
}
