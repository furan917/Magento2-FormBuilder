<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface;
use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Api\FieldType\BuilderControlsInterface;
class Checkboxes extends AbstractOptionType implements StatsAwareTypeInterface, AdminViewTypeInterface, BuilderControlsInterface
{
    public function getCode(): string
    {
        return 'checkboxes';
    }

    public function getLabel(): string
    {
        return (string)__('Checkboxes');
    }

    public function isMultiValue(): bool
    {
        return true;
    }

    public function getAllowedRules(): array
    {
        return ['min_selected', 'max_selected'];
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
        return self::KIND_MULTI;
    }

    public function adminViewKind(array $field): ?string
    {
        return self::KIND_CHIPS;
    }

    public function builderControls(): array
    {
        return ['placeholder' => false, 'autocomplete' => false, 'option_description' => false];
    }
}
