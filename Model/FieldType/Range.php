<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Model\Validation\Patterns;
use Furan\FormBuilder\Api\FieldType\BuilderControlsInterface;

class Range extends AbstractFieldType implements StatsAwareTypeInterface, BuilderControlsInterface
{
    private const EPSILON = 1e-9;
    private const MAX_DISCRETE_STEPS = 21;

    public function getCode(): string
    {
        return 'range';
    }

    public function getLabel(): string
    {
        return (string)__('Range');
    }

    public function getCategory(): string
    {
        return 'choice';
    }

    public function getSettingsSpec(): array
    {
        return [
            'min' => ['type' => 'number', 'default' => 0, 'not_above' => 'max'],
            'max' => ['type' => 'number', 'default' => 100],
            'step' => ['type' => 'number', 'default' => 1, 'exclusive_min' => 0],
        ];
    }

    public function validateValue(string|array $value, array $field, array $context): ?string
    {
        if (!is_string($value) || !preg_match(Patterns::NUMERIC, $value)) {
            return $this->getInvalidMessage($field);
        }
        [$min, $max, $step] = $this->getBounds($field);
        $number = (float)$value;
        if ($number < $min - self::EPSILON || $number > $max + self::EPSILON) {
            return $this->getInvalidMessage($field);
        }
        if ($step > 0) {
            $steps = ($number - $min) / $step;
            if (abs($steps - round($steps)) * $step > self::EPSILON) {
                return $this->getInvalidMessage($field);
            }
        }
        return null;
    }


    /**
     * @param array<string, mixed> $field
     */
    public function getInvalidMessage(array $field): ?string
    {
        [$min, $max] = $this->getBounds($field);
        return (string)__('Please choose a value between %1 and %2.', $min + 0, $max + 0);
    }

    /**
     * @param array<string, mixed> $field
     * @return float[]
     */
    private function getBounds(array $field): array
    {
        $settings = $field['settings'] ?? [];
        return [
            (float)($settings['min'] ?? 0),
            (float)($settings['max'] ?? 100),
            (float)($settings['step'] ?? 1),
        ];
    }

    public function statsKind(array $field): ?int
    {
        [$min, $max, $step] = $this->getBounds($field);
        $discrete = $step > 0 && $max >= $min && floor(round(($max - $min) / $step, 9)) + 1 <= self::MAX_DISCRETE_STEPS;
        return $discrete ? self::KIND_DISCRETE : self::KIND_NUMBER;
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
