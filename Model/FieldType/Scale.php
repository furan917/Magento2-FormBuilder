<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface;
use Furan\FormBuilder\Api\FieldType\RenderHintsInterface;
use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Api\FieldType\BuilderControlsInterface;
class Scale extends AbstractFieldType implements StatsAwareTypeInterface, AdminViewTypeInterface, RenderHintsInterface, BuilderControlsInterface
{
    public const DEFAULT_MIN = 1;
    public const DEFAULT_MAX = 5;

    public function getCode(): string
    {
        return 'scale';
    }

    public function getLabel(): string
    {
        return (string)__('Scale');
    }

    public function getCategory(): string
    {
        return 'choice';
    }

    public function getSettingsSpec(): array
    {
        return [
            'min' => ['type' => 'int', 'default' => self::DEFAULT_MIN, 'min' => 0, 'max' => 1, 'values' => ['0', '1']],
            'max' => ['type' => 'int', 'default' => self::DEFAULT_MAX, 'min' => 2, 'max' => 10],
            'low_label' => ['type' => 'string', 'translatable' => true, 'default' => '', 'max_length' => 60],
            'high_label' => ['type' => 'string', 'translatable' => true, 'default' => '', 'max_length' => 60],
        ];
    }

    public function validateValue(string|array $value, array $field, array $context): ?string
    {
        [$min, $max] = $this->getBounds($field);
        if (is_string($value) && preg_match('/^[0-9]{1,2}$/D', $value) && (int)$value >= $min && (int)$value <= $max) {
            return null;
        }
        return $this->getInvalidMessage($field);
    }

    /**
     * @param array<string, mixed> $field
     */
    public function getInvalidMessage(array $field): ?string
    {
        [$min, $max] = $this->getBounds($field);
        return (string)__('Please choose a value between %1 and %2.', $min, $max);
    }

    /**
     * @param array<string, mixed> $field
     * @return int[]
     */
    private function getBounds(array $field): array
    {
        $settings = $field['settings'] ?? [];
        return [(int)($settings['min'] ?? self::DEFAULT_MIN), (int)($settings['max'] ?? self::DEFAULT_MAX)];
    }

    public function statsKind(array $field): ?int
    {
        return self::KIND_DISCRETE;
    }

    public function adminViewKind(array $field): ?string
    {
        return self::KIND_SCALE;
    }

    /** @return array{layout: string} */
    public function renderHints(array $field, int $storeId): array
    {
        return ['layout' => 'inline'];
    }

    public function builderControls(): array
    {
        return ['placeholder' => false, 'autocomplete' => false];
    }

    public function defaultInput(): ?string
    {
        return 'number';
    }
}
