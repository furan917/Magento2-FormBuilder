<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface;
use Furan\FormBuilder\Api\FieldType\FreeTextTypeInterface;
use Furan\FormBuilder\Api\FieldType\PresetGroupsInterface;
class Textarea extends AbstractFieldType implements FreeTextTypeInterface, AdminViewTypeInterface, PresetGroupsInterface
{
    public function getCode(): string
    {
        return 'textarea';
    }

    public function getLabel(): string
    {
        return (string)__('Text area');
    }

    protected const MAX_LENGTH = 20000;

    public function getAllowedRules(): array
    {
        return ['min_length', 'max_length', 'pattern', 'preset'];
    }
    public function getSettingsSpec(): array
    {
        return ['rows' => ['type' => 'int', 'default' => 5, 'min' => 2, 'max' => 20]];
    }

    protected function keepsNewlines(): bool
    {
        return true;
    }

    public function isFreeText(): bool
    {
        return true;
    }

    public function adminViewKind(array $field): ?string
    {
        return self::KIND_MULTILINE;
    }

    public function presetGroups(): array
    {
        return ['content'];
    }
}
