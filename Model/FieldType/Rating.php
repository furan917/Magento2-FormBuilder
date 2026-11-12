<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface;
use Furan\FormBuilder\Api\FieldType\RenderHintsInterface;
use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Api\FieldType\BuilderControlsInterface;
class Rating extends AbstractFieldType implements StatsAwareTypeInterface, AdminViewTypeInterface, RenderHintsInterface, BuilderControlsInterface
{
    private const DEFAULT_MAX = 5;

    public function getCode(): string
    {
        return 'rating';
    }

    public function getLabel(): string
    {
        return (string)__('Rating');
    }

    public function getCategory(): string
    {
        return 'choice';
    }

    public function getSettingsSpec(): array
    {
        return ['max' => ['type' => 'int', 'default' => self::DEFAULT_MAX, 'min' => 3, 'max' => 10, 'hint' => (string)__('From 3 to 10.')]];
    }

    public function validateValue(string|array $value, array $field, array $context): ?string
    {
        $max = $this->getMax($field);
        if (is_string($value) && preg_match('/^[0-9]{1,3}$/D', $value) && (int)$value >= 1 && (int)$value <= $max) {
            return null;
        }
        return $this->getInvalidMessage($field);
    }

    /**
     * @param array<string, mixed> $field
     */
    public function getInvalidMessage(array $field): ?string
    {
        return (string)__('Please choose a value between %1 and %2.', 1, $this->getMax($field));
    }

    /**
     * @param array<string, mixed> $field
     */
    private function getMax(array $field): int
    {
        return (int)($field['settings']['max'] ?? self::DEFAULT_MAX) ?: self::DEFAULT_MAX;
    }

    public function statsKind(array $field): ?int
    {
        return self::KIND_DISCRETE;
    }

    public function adminViewKind(array $field): ?string
    {
        return self::KIND_RATING;
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
