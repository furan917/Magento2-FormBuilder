<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

class Consent extends Checkbox
{
    public function getCode(): string
    {
        return 'consent';
    }

    public function getLabel(): string
    {
        return (string)__('Consent');
    }

    public function getSettingsSpec(): array
    {
        return [
            'details' => ['type' => 'string', 'translatable' => true, 'default' => '', 'max_length' => 1000],
            'privacy_url' => ['type' => 'string', 'translatable' => true, 'default' => '', 'format' => 'url'],
            'privacy_label' => ['type' => 'string', 'translatable' => true, 'default' => 'Privacy policy', 'max_length' => 100],
        ];
    }

    /**
     * @param array<string, mixed> $field
     */
    protected function getCheckedValue(array $field): string
    {
        return 'yes';
    }
}
