<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Api\FieldType\BuilderControlsInterface;
class Radio extends AbstractOptionType implements StatsAwareTypeInterface, BuilderControlsInterface
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
        return ['layout' => [
            'type' => 'enum',
            'default' => 'stacked',
            'values' => ['stacked', 'inline'],
            'value_labels' => ['stacked' => (string)__('Stacked'), 'inline' => (string)__('Inline')],
        ]];
    }

    public function statsKind(array $field): ?int
    {
        return self::KIND_CHOICE;
    }

    public function builderControls(): array
    {
        return ['placeholder' => false, 'autocomplete' => false, 'option_description' => false];
    }
}
