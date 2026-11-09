<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\FreeTextTypeInterface;
class Text extends AbstractFieldType implements FreeTextTypeInterface
{
    public function getCode(): string
    {
        return 'text';
    }

    public function getLabel(): string
    {
        return (string)__('Text');
    }

    public function getAllowedRules(): array
    {
        return ['min_length', 'max_length', 'pattern', 'preset', 'alpha', 'alphanumeric', 'matches'];
    }

    public function getSettingsSpec(): array
    {
        return [
            'prefix' => ['type' => 'string', 'translatable' => true, 'default' => '', 'max_length' => 20],
            'suffix' => ['type' => 'string', 'translatable' => true, 'default' => '', 'max_length' => 20],
            'mask' => $this->maskSpec(),
        ];
    }

    public function isFreeText(): bool
    {
        return true;
    }
}
