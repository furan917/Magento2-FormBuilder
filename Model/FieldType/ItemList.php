<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface;

/**
 * Repeatable text: one input per item with an "add another" button. Posted as name[]; empty items are dropped.
 */
class ItemList extends AbstractFieldType implements AdminViewTypeInterface
{
    protected const MAX_LENGTH = 255;
    private const DEFAULT_MAX_ITEMS = 5;
    private const INVALID_ENTRY = "\0";

    public function getCode(): string
    {
        return 'list';
    }

    public function getLabel(): string
    {
        return (string)__('List');
    }

    public function isMultiValue(): bool
    {
        return true;
    }

    public function getSettingsSpec(): array
    {
        return [
            'max_items' => ['type' => 'int', 'default' => self::DEFAULT_MAX_ITEMS, 'min' => 2, 'max' => 20],
            'add_label' => ['type' => 'string', 'translatable' => true, 'default' => '', 'max_length' => 40],
        ];
    }

    public function normalizeValue(mixed $raw, array $field): string|array|null
    {
        if (is_scalar($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            return null;
        }
        $items = [];
        foreach ($raw as $item) {
            $value = is_scalar($item) ? parent::normalizeValue($item, $field) : null;
            if ($value === null) {
                return [self::INVALID_ENTRY];
            }
            if ($value !== '') {
                $items[] = $value;
            }
        }
        return $items;
    }

    public function validateValue(string|array $value, array $field, array $context): ?string
    {
        if (!is_array($value) || in_array(self::INVALID_ENTRY, $value, true) || count($value) > $this->getMaxItems($field)) {
            return $this->getInvalidMessage($field);
        }
        return null;
    }

    /**
     * @param array<string, mixed> $field
     */
    public function getInvalidMessage(array $field): ?string
    {
        return (string)__('You can add up to %1 items.', $this->getMaxItems($field));
    }

    /**
     * @param array<string, mixed> $field
     */
    public function getMaxItems(array $field): int
    {
        return max(2, min(20, (int)($field['settings']['max_items'] ?? self::DEFAULT_MAX_ITEMS)));
    }

    public function adminViewKind(array $field): ?string
    {
        return self::KIND_LIST;
    }
}
