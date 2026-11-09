<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

abstract class AbstractOptionType extends AbstractFieldType
{
    private const MAX_VALUES = 500;

    public function getCategory(): string
    {
        return 'choice';
    }

    public function hasOptions(): bool
    {
        return true;
    }

    public function normalizeValue(mixed $raw, array $field): string|array|null
    {
        if (!$this->isMultiValue()) {
            return parent::normalizeValue($raw, $field);
        }
        $items = is_array($raw) ? $raw : [$raw];
        $values = [];
        foreach ($items as $item) {
            $value = parent::normalizeValue($item, $field);
            if (!is_string($value)) {
                return null;
            }
            if ($value !== '') {
                $values[$value] = $value;
            }
        }
        return array_slice(array_values(array_map('strval', $values)), 0, self::MAX_VALUES);
    }

    public function validateValue(string|array $value, array $field, array $context): ?string
    {
        $allowed = [];
        foreach ($field['options'] ?? [] as $option) {
            $allowed[] = (string)($option['value'] ?? '');
        }
        foreach ((array)$value as $item) {
            if (!is_string($item) || !in_array($item, $allowed, true)) {
                return $this->getInvalidMessage($field);
            }
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
}
