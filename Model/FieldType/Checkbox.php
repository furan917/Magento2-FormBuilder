<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface;
use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
class Checkbox extends AbstractFieldType implements StatsAwareTypeInterface, AdminViewTypeInterface
{
    public function getCode(): string
    {
        return 'checkbox';
    }

    public function getLabel(): string
    {
        return (string)__('Checkbox');
    }

    public function getCategory(): string
    {
        return 'choice';
    }

    public function getSettingsSpec(): array
    {
        return ['checked_value' => ['type' => 'string', 'default' => 'yes']];
    }

    public function validateValue(string|array $value, array $field, array $context): ?string
    {
        return $value === $this->getCheckedValue($field) ? null : $this->getInvalidMessage($field);
    }

    /**
     * @param array<string, mixed> $field
     */
    public function getInvalidMessage(array $field): ?string
    {
        return (string)__('Please choose a valid option.');
    }

    /**
     * @param array<string, mixed> $field
     */
    protected function getCheckedValue(array $field): string
    {
        $value = (string)($field['settings']['checked_value'] ?? '');
        return $value !== '' ? $value : 'yes';
    }

    public function statsKind(array $field): ?int
    {
        return self::KIND_CHOICE;
    }

    public function adminViewKind(array $field): ?string
    {
        return self::KIND_BOOLEAN;
    }
}
