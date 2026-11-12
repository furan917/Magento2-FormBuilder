<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Schema;

use Furan\FormBuilder\Api\FieldType\DefaultValueInterface;
use Furan\FormBuilder\Api\FieldType\MaxLengthInterface;
use Furan\FormBuilder\Api\FieldType\SettingsCheckInterface;
use Furan\FormBuilder\Api\FieldTypeInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\FieldType\SettingLabels;
use Furan\FormBuilder\Model\Upload\ExtensionList;
use Furan\FormBuilder\Model\Validation\RulePool;

/**
 * Save-time checks of a field's file types, settings, default value and bounds. Each error is
 * `Field "<label>": <fragment>.` with the fragments builder-model.js validate() uses too.
 *
 * @phpstan-import-type SettingSpec from \Furan\FormBuilder\Api\FieldTypeInterface
 */
class FieldChecks
{
    public const MAX_DEFAULT_LENGTH = 1000;
    /** Lower bound rule => upper bound rule. */
    private const RULE_PAIRS = ['min' => 'max', 'min_length' => 'max_length', 'min_date' => 'max_date', 'min_selected' => 'max_selected'];

    public function __construct(
        private readonly RulePool $rules,
        private readonly Config $config,
        private readonly SettingLabels $settingLabels,
        private readonly ExtensionList $extensionList
    ) {
    }

    /**
     * @param array<mixed> $extensions
     * @param string[] $errors
     * @return list<string> lower case, without dots, each once
     */
    public function extensions(array $extensions, string $label, array &$errors): array
    {
        $clean = $this->extensionList->normalise($extensions);
        // The upload allowlist is default-scope only.
        $allowed = $this->config->getAllowedExtensions();
        $refused = array_merge(
            $this->extensionList->malformed($extensions),
            array_filter($clean, static fn (string $e): bool => !in_array($e, $allowed, true))
        );
        if ($refused !== []) {
            sort($refused);
            $errors[] = $this->error($label, __('these file types are not allowed by the store\'s upload settings: %1', implode(', ', $refused)));
        }
        return $clean;
    }

    /**
     * A SettingValues problem of a field setting, as a save error.
     *
     * @param array<string, SettingSpec> $spec
     */
    public function settingProblem(string $kind, string $key, mixed $argument, array $spec, string $label): ?string
    {
        $name = $this->settingLabel($spec, $key);
        $fragment = match ($kind) {
            SettingValues::PROBLEM_ABOVE => __('"%1" is greater than "%2"', $name, $this->settingLabel($spec, (string)$argument)),
            SettingValues::PROBLEM_EXCLUSIVE_MIN => __('"%1" must be greater than %2', $name, $argument),
            SettingValues::PROBLEM_DATE => __('"%1" must be a date such as 2025-01-31', $name),
            SettingValues::PROBLEM_STEP => __('"%1" must be "any" or a number greater than 0', $name),
            default => null,
        };
        return $fragment === null ? null : $this->error($label, $fragment);
    }

    /**
     * @param array<string, mixed> $field the normalised field without its default
     * @param string $default cleaned and trimmed, cut at one character over the limit
     * @param string[] $errors
     * @return string the default to store
     */
    public function defaultValue(FieldTypeInterface $type, array $field, string $default, string $label, array &$errors): string
    {
        $input = $type instanceof DefaultValueInterface ? $type->defaultInput() : DefaultValueInterface::INPUT_TEXT;
        if ($default === '' || $input === null) {
            return '';
        }
        $max = min(self::MAX_DEFAULT_LENGTH, $type instanceof MaxLengthInterface ? $type->maxLength() : self::MAX_DEFAULT_LENGTH);
        if (mb_strlen($default) > $max) {
            $errors[] = $this->error($label, __('the default value is longer than %1 characters', $max));
            return '';
        }
        // The storefront preselects one option whose value equals the Default, also on multi-value types.
        if ($input === DefaultValueInterface::INPUT_OPTION) {
            if (!$this->isValidDefault($type, $default, $field)) {
                $errors[] = $this->error($label, __('the default value "%1" is not one of its options', $default));
            }
            return $default;
        }
        $normalized = $type->normalizeValue($default, $field);
        if (!is_string($normalized) || $normalized === '' || !$this->isValidDefault($type, $normalized, $field)) {
            $errors[] = $this->error($label, __('the default value "%1" is not valid for this field', $default));
            return $default;
        }
        foreach ($field['rules'] ?? [] as $rule) {
            $code = (string)$rule['rule'];
            $check = $this->rules->has($code) ? $this->rules->get($code) : null;
            if ($check !== null && $check->getValueType() !== 'field'
                && !$check->validate($normalized, $rule['value'], ['values' => []])
            ) {
                $errors[] = $this->error($label, __('the default value "%1" breaks the rule "%2"', $default, $check->getLabel()));
            }
        }
        return $normalized;
    }

    /**
     * @param array<string, mixed> $field the normalised field
     * @param string[] $errors
     */
    public function bounds(FieldTypeInterface $type, array $field, string $label, array &$errors): void
    {
        $values = array_column($field['rules'] ?? [], 'value', 'rule');
        foreach (self::RULE_PAIRS as $low => $high) {
            if (isset($values[$low], $values[$high]) && $values[$low] > $values[$high]) {
                $errors[] = $this->error($label, __('"%1" is greater than "%2"', $this->ruleLabel($low), $this->ruleLabel($high)));
            }
        }
        $cap = $type instanceof MaxLengthInterface ? $type->maxLength() : null;
        if (isset($values['max_length']) && $cap !== null && $values['max_length'] > $cap) {
            $errors[] = $this->error($label, __('"%1" can be at most %2', $this->ruleLabel('max_length'), $cap));
        }
        $optionCount = count($field['options'] ?? []);
        if (isset($values['min_selected']) && $type->hasOptions() && $values['min_selected'] > $optionCount) {
            $errors[] = $this->error($label, __('"%1" is more than the number of options (%2)', $this->ruleLabel('min_selected'), $optionCount));
        }
        if ($type instanceof SettingsCheckInterface) {
            foreach ($type->settingsErrors($field) as $problem) {
                $errors[] = $this->error($label, $problem);
            }
        }
    }

    /**
     * @param array<string, mixed> $field
     */
    private function isValidDefault(FieldTypeInterface $type, string $default, array $field): bool
    {
        return $type instanceof DefaultValueInterface
            ? $type->isValidDefault($default, $field)
            : $type->validateValue($default, $field, ['values' => [], 'store_id' => 0]) === null;
    }

    /**
     * @param array<string, SettingSpec> $spec
     */
    private function settingLabel(array $spec, string $key): string
    {
        return (string)($spec[$key]['label'] ?? $this->settingLabels->label($key) ?? $key);
    }

    private function ruleLabel(string $code): string
    {
        return $this->rules->has($code) ? $this->rules->get($code)->getLabel() : $code;
    }

    private function error(string $label, mixed $fragment): string
    {
        return (string)__('Field "%1": %2.', $label, (string)$fragment);
    }
}
