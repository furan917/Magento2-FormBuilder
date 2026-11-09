<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Api\FieldType\PersonalByDefaultInterface;

class File extends AbstractFieldType implements PersonalByDefaultInterface, StatsAwareTypeInterface
{
    public function getCode(): string
    {
        return 'file';
    }

    public function isPersonalByDefault(): bool
    {
        return true;
    }

    public function getLabel(): string
    {
        return (string)__('File upload');
    }

    public function getCategory(): string
    {
        return 'advanced';
    }

    public function isFile(): bool
    {
        return true;
    }

    public function getSettingsSpec(): array
    {
        return [
            'extensions' => ['type' => 'list', 'default' => []],
            'max_size_mb' => ['type' => 'int', 'default' => 0, 'min' => 0, 'max' => 100],
        ];
    }

    public function validateValue(string|array $value, array $field, array $context): ?string
    {
        return null;
    }

    public function statsKind(array $field): ?int
    {
        return self::KIND_NONE;
    }
}
