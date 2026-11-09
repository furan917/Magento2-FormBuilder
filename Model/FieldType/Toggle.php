<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

class Toggle extends Checkbox
{
    public function getCode(): string
    {
        return 'toggle';
    }

    public function getLabel(): string
    {
        return (string)__('Toggle');
    }

    public function getSettingsSpec(): array
    {
        return ['on_value' => ['type' => 'string', 'default' => 'yes']];
    }

    /**
     * @param array<string, mixed> $field
     */
    protected function getCheckedValue(array $field): string
    {
        $value = (string)($field['settings']['on_value'] ?? '');
        return $value !== '' ? $value : 'yes';
    }
}
