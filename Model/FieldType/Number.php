<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\ClientMessagesInterface;
use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Model\Validation\Patterns;

class Number extends AbstractPatternType implements StatsAwareTypeInterface, ClientMessagesInterface
{
    /** Mirrors ffb-core stepError (fixture Test/fixtures/field-values.json). */
    private const STEP_TOLERANCE = 1e-9;

    public function getCode(): string
    {
        return 'number';
    }

    public function getLabel(): string
    {
        return (string)__('Number');
    }

    public function getAllowedRules(): array
    {
        return ['min', 'max', 'integer'];
    }

    public function getSettingsSpec(): array
    {
        return [
            'step' => ['type' => 'string', 'default' => 'any'],
            'prefix' => ['type' => 'string', 'translatable' => true, 'default' => '', 'max_length' => 20],
            'suffix' => ['type' => 'string', 'translatable' => true, 'default' => '', 'max_length' => 20],
        ];
    }

    /**
     * @param array<string, mixed> $field
     */
    public function getInvalidMessage(array $field): ?string
    {
        return (string)__('Please enter a valid number.');
    }

    public function validateValue(string|array $value, array $field, array $context): ?string
    {
        $message = parent::validateValue($value, $field, $context);
        if ($message !== null || !is_string($value)) {
            return $message;
        }
        $step = $this->step($field);
        if ($step === null) {
            return null;
        }
        $base = $this->base($field);
        $steps = ((float)$value - $base) / $step;

        return abs($steps - round($steps)) <= self::STEP_TOLERANCE * max(1.0, abs($steps)) ? null : $this->stepMessage($field);
    }

    public function getClientMessages(array $field): array
    {
        return $this->step($field) === null ? [] : ['messages' => ['step' => $this->stepMessage($field)]];
    }

    /**
     * @param array<string, mixed> $field
     */
    private function stepMessage(array $field): string
    {
        $step = (string)$field['settings']['step'];

        return (float)$step === 1.0 && floor($this->base($field)) === $this->base($field)
            ? (string)__('Please enter a whole number.')
            : (string)__('Please enter a number in steps of %1.', $step);
    }

    /**
     * Step "any", empty or malformed means no step check, as in the browser.
     *
     * @param array<string, mixed> $field
     */
    private function step(array $field): ?float
    {
        $step = $field['settings']['step'] ?? null;
        if (!is_string($step) && !is_int($step) && !is_float($step)) {
            return null;
        }
        $step = (string)$step;

        return preg_match('/^\d+(\.\d+)?$/D', $step) === 1 && (float)$step > 0 ? (float)$step : null;
    }

    /**
     * Steps count from the min rule, like the input's own step attribute.
     *
     * @param array<string, mixed> $field
     */
    private function base(array $field): float
    {
        foreach ((array)($field['rules'] ?? []) as $rule) {
            $min = is_array($rule) && ($rule['rule'] ?? '') === 'min' ? ($rule['value'] ?? null) : null;
            if ((is_string($min) || is_int($min) || is_float($min)) && preg_match(Patterns::NUMERIC, (string)$min) === 1) {
                return (float)$min;
            }
        }

        return 0.0;
    }

    protected function getPattern(): string
    {
        return Patterns::NUMERIC;
    }

    public function statsKind(array $field): ?int
    {
        return self::KIND_NUMBER;
    }
}
