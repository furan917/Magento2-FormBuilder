<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Api\FieldType\BuilderControlsInterface;
class Select extends AbstractOptionType implements StatsAwareTypeInterface, BuilderControlsInterface
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

    public function builderControls(): array
    {
        return ['placeholder' => false, 'option_description' => false];
    }
}
