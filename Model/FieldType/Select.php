<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
class Select extends AbstractOptionType implements StatsAwareTypeInterface
{
    public function getCode(): string
    {
        return 'select';
    }

    public function getLabel(): string
    {
        return (string)__('Select');
    }

    public function getSettingsSpec(): array
    {
        return ['empty_label' => ['type' => 'string', 'translatable' => true, 'default' => '']];
    }

    public function statsKind(array $field): ?int
    {
        return self::KIND_CHOICE;
    }
}
