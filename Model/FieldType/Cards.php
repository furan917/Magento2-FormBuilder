<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

class Cards extends Radio
{
    public function getCode(): string
    {
        return 'cards';
    }

    public function getLabel(): string
    {
        return (string)__('Cards');
    }

    public function getSettingsSpec(): array
    {
        return [];
    }
}
