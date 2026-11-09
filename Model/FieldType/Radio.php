<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
class Radio extends AbstractOptionType implements StatsAwareTypeInterface
{
    public function getCode(): string
    {
        return 'radio';
    }

    public function getLabel(): string
    {
        return (string)__('Radio');
    }

    public function getSettingsSpec(): array
    {
        return ['layout' => ['type' => 'enum', 'default' => 'stacked', 'values' => ['stacked', 'inline']]];
    }

    public function statsKind(array $field): ?int
    {
        return self::KIND_CHOICE;
    }
}
