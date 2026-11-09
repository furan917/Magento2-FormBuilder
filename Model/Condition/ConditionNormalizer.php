<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition;

use Furan\FormBuilder\Api\ConditionActionInterface;
use Furan\FormBuilder\Api\FieldType\ConsentTypeInterface;
use Furan\FormBuilder\Api\FieldType\RelativeDateSourceInterface;
use Furan\FormBuilder\Api\FieldTypeInterface;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Schema\SettingValues;

class ConditionNormalizer
{
    /** The condition itself is level 1; each nested group adds one level. */
    public const MAX_LEVELS = 3;
    /** Rules and groups together, at every level. */
    public const MAX_ENTRIES = 25;
    public const MAX_VALUE_LENGTH = 1000;
    public const MAX_RELATIVE_DAYS = 3650;
    public const MAX_ACTIONS = 10;
    /** Actions a consent (newsletter) field refuses. */
    public const CONSENT_REFUSED_ACTIONS = ['require', 'set_value'];

    public function __construct(
        private readonly OperatorPool $operators,
        private readonly Pool $fieldTypes,
        private readonly SettingValues $settingValues,
        private readonly ActionPool $actions
    ) {
    }

    /**
     * A condition with actions, for a page, section, field or content item.
     *
     * @param array<string, string> $allowedSources field name => type code of the fields a rule may use
     * @param array<int, array<string, mixed>> $storedRules flat rules of the saved item (see flatten())
     * @param string[] $errors
     * @param array{kind?: string, type?: FieldTypeInterface, field?: array<string, mixed>, refuse_page_only?: bool, stored_actions?: array<int, array<string, mixed>>} $target
     *     the item the condition sits on: its kind, and for fields the type, the field so far (options, settings)
     *     and the saved actions; refuse_page_only turns away actions that only work on pages. Empty = no target checks.
     * @return array{actions: list<array<string, mixed>>, match: string, rules: array<int, array<string, mixed>>}|null
     */
    public function normalize(
        mixed $raw,
        array $allowedSources,
        array $storedRules,
        string $itemLabel,
        array &$errors,
        array $target = []
    ): ?array {
        $core = $this->normalizeRules($raw, $allowedSources, $storedRules, $itemLabel, $errors);
        if ($core === null || !is_array($raw)) {
            return null;
        }
        return ['actions' => $this->normalizeActions($raw, $itemLabel, $target, $errors)] + $core;
    }

    /**
     * Rules without actions: page skips, notification routes and send-when conditions. Rules that all turn out
     * empty give null, which for these means "never".
     *
     * @param array<string, string> $allowedSources
     * @param array<int, array<string, mixed>> $storedRules
     * @param string[] $errors
     * @param bool $earlierOnly false when the sources are not ordered (routes, send-when): the error then says
     *     the field is missing or not routable
     * @return array{match: string, rules: array<int, array<string, mixed>>}|null
     */
    public function normalizeRules(
        mixed $raw,
        array $allowedSources,
        array $storedRules,
        string $itemLabel,
        array &$errors,
        bool $earlierOnly = true
    ): ?array {
        if (!is_array($raw) || !is_array($raw['rules'] ?? null) || $raw['rules'] === []) {
            return null;
        }
        $remaining = self::MAX_ENTRIES;
        $scope = ['sources' => $allowedSources, 'stored' => $storedRules, 'label' => $itemLabel, 'earlier' => $earlierOnly];
        $rules = $this->normalizeRuleList(array_values($raw['rules']), 1, $scope, $remaining, $errors);
        return $rules === [] ? null : ['match' => ($raw['match'] ?? null) === 'any' ? 'any' : 'all', 'rules' => $rules];
    }

    /**
     * Drops the rules that name a field outside `$sources` (in `field` or `value_field`, at any depth), then the
     * groups and the condition that end up empty. For stored conditions an admin cannot edit.
     *
     * @param array<string, string> $sources
     * @return array<string, mixed>|null
     */
    public function prune(mixed $condition, array $sources): ?array
    {
        if (!is_array($condition) || !is_array($condition['rules'] ?? null)) {
            return null;
        }
        $rules = [];
        foreach ($condition['rules'] as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if (self::isGroup($rule)) {
                $group = $this->prune($rule, $sources);
                if ($group !== null) {
                    $rules[] = $group;
                }
                continue;
            }
            foreach ([$rule['field'] ?? null, $rule['value_field'] ?? null] as $name) {
                if (is_string($name) && !isset($sources[$name])) {
                    continue 2;
                }
            }
            $rules[] = $rule;
        }

        return $rules === [] ? null : array_merge($condition, ['rules' => $rules]);
    }

    /**
     * The rules of a saved condition without their groups.
     *
     * @return array<int, array<string, mixed>>
     */
    public function flatten(mixed $condition): array
    {
        $flat = [];
        $rules = is_array($condition) && is_array($condition['rules'] ?? null) ? $condition['rules'] : [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if (self::isGroup($rule)) {
                array_push($flat, ...$this->flatten($rule));
                continue;
            }
            $flat[] = $rule;
        }
        return $flat;
    }

    /**
     * The actions of a stored condition; a condition without `actions` shows the item when it matches.
     *
     * @param array<string, mixed> $condition
     * @return list<array{action: string, param?: mixed}>
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- a pure shape reader shared with the Evaluator
    public static function actionsOf(array $condition): array
    {
        if (is_array($condition['actions'] ?? null)) {
            $actions = [];
            foreach ($condition['actions'] as $entry) {
                if (is_array($entry) && is_string($entry['action'] ?? null)) {
                    $actions[] = array_key_exists('param', $entry)
                        ? ['action' => $entry['action'], 'param' => $entry['param']]
                        : ['action' => $entry['action']];
                }
            }
            return $actions;
        }
        return [['action' => 'show']];
    }

    /** Consent is neither required nor pre-filled by a condition. */
    // phpcs:ignore Magento2.Functions.StaticFunction -- a pure rule shared with the builder config
    public static function actionFits(ConditionActionInterface $action, FieldTypeInterface $type): bool
    {
        return !($type instanceof ConsentTypeInterface && in_array($action->getCode(), self::CONSENT_REFUSED_ACTIONS, true));
    }

    /**
     * @param array<string, mixed> $rule
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- a pure shape test shared with the Evaluator
    public static function isGroup(array $rule): bool
    {
        return !array_key_exists('field', $rule) && is_array($rule['rules'] ?? null);
    }

    /**
     * @param array<int, mixed> $rawRules
     * @param array<string, mixed> $scope
     * @param string[] $errors
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRuleList(array $rawRules, int $level, array $scope, int &$remaining, array &$errors): array
    {
        $rules = [];
        foreach ($rawRules as $rawRule) {
            if ($remaining <= 0) {
                if ($remaining === 0) {
                    $remaining = -1;
                    $errors[] = (string)__(
                        '"%1" can have at most %2 condition rules.',
                        $scope['label'],
                        self::MAX_ENTRIES
                    );
                }
                break;
            }
            $remaining--;
            $rawRule = is_array($rawRule) ? $rawRule : [];
            $rule = self::isGroup($rawRule)
                ? $this->normalizeGroup($rawRule, $level + 1, $scope, $remaining, $errors)
                : $this->normalizeRule($rawRule, $scope, $errors);
            if ($rule !== null) {
                $rules[] = $rule;
            }
        }
        return $rules;
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $scope
     * @param string[] $errors
     * @return array<string, mixed>|null
     */
    private function normalizeGroup(array $raw, int $level, array $scope, int &$remaining, array &$errors): ?array
    {
        if ($level > self::MAX_LEVELS) {
            $errors[] = (string)__(
                '"%1": condition groups can be nested at most %2 levels deep.',
                $scope['label'],
                self::MAX_LEVELS
            );
            return null;
        }
        $rules = $this->normalizeRuleList(array_values($raw['rules']), $level, $scope, $remaining, $errors);
        return $rules === [] ? null : ['match' => ($raw['match'] ?? null) === 'any' ? 'any' : 'all', 'rules' => $rules];
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $scope
     * @param string[] $errors
     * @return array<string, mixed>|null
     */
    private function normalizeRule(array $raw, array $scope, array &$errors): ?array
    {
        $field = $raw['field'] ?? null;
        if (!is_string($field) || !isset($scope['sources'][$field])) {
            $errors[] = $this->earlierFieldError($scope);
            return null;
        }
        $operator = $raw['operator'] ?? null;
        if (!is_string($operator)) {
            $errors[] = $this->unknownOperatorError($scope);
            return null;
        }
        $rule = $this->buildRule($raw, $field, $operator, $scope, $errors);
        if ($rule === null) {
            return null;
        }
        // Checked before anything else: a disabled operator cannot describe its own rule.
        if (!$this->operators->has($operator)) {
            if (in_array($rule, $scope['stored'], true)) {
                return $rule;
            }
            $errors[] = $this->unknownOperatorError($scope);
            return null;
        }
        $typeCode = $scope['sources'][$field];
        $pooled = $this->operators->get($operator);
        if (!$pooled->appliesTo($typeCode)) {
            $errors[] = $this->unknownOperatorError($scope);
            return null;
        }
        $comparesValue = isset($rule['value_field']) || isset($rule['value_date']);
        if ($comparesValue && !$pooled->needsValue()) {
            $errors[] = $this->singleValueSourceError($scope);
            return null;
        }
        if (isset($rule['value_date']) && !$this->isRelativeDateSource($typeCode)) {
            $errors[] = (string)__('"%1": a relative date needs a date field.', $scope['label']);
            return null;
        }
        return $rule;
    }

    /**
     * Exactly one value source survives: a literal, another field or a relative date.
     *
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $scope
     * @param string[] $errors
     * @return array<string, mixed>|null
     */
    private function buildRule(array $raw, string $field, string $operator, array $scope, array &$errors): ?array
    {
        $head = ['field' => $field, 'operator' => $operator];
        $hasField = isset($raw['value_field']);
        $hasDate = isset($raw['value_date']);
        if ($hasField && $hasDate) {
            $errors[] = $this->singleValueSourceError($scope);
            return null;
        }
        if ($hasField) {
            if (!is_string($raw['value_field']) || !isset($scope['sources'][$raw['value_field']])) {
                $errors[] = $this->earlierFieldError($scope);
                return null;
            }
            return $head + ['value_field' => $raw['value_field']];
        }
        if ($hasDate) {
            $days = is_array($raw['value_date']) ? $this->parseDays($raw['value_date']['days'] ?? null) : null;
            if ($days === null) {
                $errors[] = (string)__(
                    '"%1": a relative date must be a whole number of days between -%2 and %2.',
                    $scope['label'],
                    self::MAX_RELATIVE_DAYS
                );
                return null;
            }
            return $head + ['value_date' => ['days' => $days]];
        }
        return $head + ['value' => $this->settingValues->cleanString($raw['value'] ?? '', self::MAX_VALUE_LENGTH, true)];
    }

    /**
     * No `actions` means "show".
     *
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $target
     * @param string[] $errors
     * @return list<array<string, mixed>>
     */
    private function normalizeActions(array $raw, string $label, array $target, array &$errors): array
    {
        if (!is_array($raw['actions'] ?? null)) {
            return [['action' => 'show']];
        }
        $entries = array_values($raw['actions']);
        if ($entries === []) {
            return [['action' => 'show']];
        }
        if (count($entries) > self::MAX_ACTIONS) {
            $errors[] = (string)__('"%1" can have at most %2 actions.', $label, self::MAX_ACTIONS);
            $entries = array_slice($entries, 0, self::MAX_ACTIONS);
        }
        $actions = [];
        $seen = [];
        foreach ($entries as $entry) {
            $action = $this->normalizeAction($entry, $label, $target, $errors);
            if ($action === null) {
                continue;
            }
            if (isset($seen[$action['action']])) {
                $errors[] = (string)__('"%1": each action can be used only once.', $label);
                continue;
            }
            $seen[$action['action']] = true;
            $actions[] = $action;
        }
        if (isset($seen['show'], $seen['hide'])) {
            $errors[] = (string)__('"%1": use either show or hide, not both.', $label);
        }
        return $actions;
    }

    /**
     * @param array<string, mixed> $target
     * @param string[] $errors
     * @return array<string, mixed>|null
     */
    private function normalizeAction(mixed $entry, string $label, array $target, array &$errors): ?array
    {
        $code = is_array($entry) && is_string($entry['action'] ?? null) ? $entry['action'] : '';
        if (!$this->actions->has($code)) {
            $kept = $this->storedUnknownAction($entry, $code, $target['stored_actions'] ?? []);
            if ($kept === null) {
                $errors[] = (string)__('"%1": unknown condition action.', $label);
            }
            return $kept;
        }
        $action = $this->actions->get($code);
        $kind = $target['kind'] ?? null;
        if ($kind !== null && !in_array($kind, $action->targets(), true)
            || !empty($target['refuse_page_only']) && $action->targets() === ['page']
        ) {
            $errors[] = (string)__('"%1": the action "%2" cannot be used on this item.', $label, $action->getLabel());
            return null;
        }
        $type = $target['type'] ?? null;
        if ($type instanceof FieldTypeInterface && !self::actionFits($action, $type)) {
            $errors[] = (string)__('"%1": consent cannot be required or pre-filled by a condition.', $label);
            return null;
        }
        $param = $this->normalizeParam($action, $entry['param'] ?? null, $target, $label, $errors);
        if ($action->needsParam() !== null && $param === null) {
            return null;
        }
        return $param === null ? ['action' => $code] : ['action' => $code, 'param' => $param];
    }

    /**
     * An action of a disabled extension survives only as the identical action of the saved item.
     *
     * @param array<int, array<string, mixed>> $stored
     * @return array<string, mixed>|null
     */
    private function storedUnknownAction(mixed $entry, string $code, array $stored): ?array
    {
        if ($code === '' || !is_array($entry)) {
            return null;
        }
        $candidate = array_key_exists('param', $entry) ? ['action' => $code, 'param' => $entry['param']] : ['action' => $code];
        return in_array($candidate, $stored, true) ? $candidate : null;
    }

    /**
     * @param array<string, mixed> $target
     * @param string[] $errors
     * @return string|list<string>|null null = no parameter, or an invalid one (already reported)
     */
    private function normalizeParam(
        ConditionActionInterface $action,
        mixed $param,
        array $target,
        string $label,
        array &$errors
    ): string|array|null {
        $kind = $action->needsParam();
        if ($kind === null) {
            return null;
        }
        if ($kind === ConditionActionInterface::PARAM_OPTIONS) {
            return $this->optionsParam($param, $target, $label, $errors);
        }
        $value = $this->settingValues->cleanString($param, self::MAX_VALUE_LENGTH, true);
        $type = $target['type'] ?? null;
        if ($type !== null && !$this->isSettableValue($type, $value, $target['field'] ?? [])) {
            $errors[] = (string)__('"%1": the value to set is not valid for this field.', $label);
            return null;
        }
        return $value;
    }

    /**
     * The value must be one the visitor could have typed: an option, or what the type itself accepts.
     *
     * @param array<string, mixed> $field
     */
    private function isSettableValue(FieldTypeInterface $type, string $value, array $field): bool
    {
        if ($type->isFile() || $type->isMultiValue()) {
            return false;
        }
        if ($type->hasOptions()) {
            return in_array($value, $this->optionValues($field), true);
        }
        if ($type->normalizeValue($value, $field) !== $value) {
            return false;
        }
        return $value === '' || $type->validateValue($value, $field, ['values' => [], 'store_id' => 0]) === null;
    }

    /**
     * @param array<string, mixed> $target
     * @param string[] $errors
     * @return list<string>|null
     */
    private function optionsParam(mixed $param, array $target, string $label, array &$errors): ?array
    {
        $type = $target['type'] ?? null;
        $allowed = $this->optionValues($target['field'] ?? []);
        $wanted = is_array($param) ? array_values(array_unique(array_map(
            static fn ($option): string => is_scalar($option) ? (string)$option : '',
            $param
        ))) : [];
        if ($wanted === [] || ($type !== null && (!$type->hasOptions() || array_diff($wanted, $allowed) !== []))) {
            $errors[] = (string)__('"%1": the options to hide must be options of this field.', $label);
            return null;
        }
        return $wanted;
    }

    /**
     * @param array<string, mixed> $field
     * @return list<string>
     */
    private function optionValues(array $field): array
    {
        return array_values(array_map(
            static fn ($option): string => (string)($option['value'] ?? ''),
            array_filter($field['options'] ?? [], is_array(...))
        ));
    }

    private function parseDays(mixed $days): ?int
    {
        if (is_string($days) && preg_match('/^-?\d{1,5}$/D', $days) === 1) {
            $days = (int)$days;
        }
        return is_int($days) && abs($days) <= self::MAX_RELATIVE_DAYS ? $days : null;
    }

    private function isRelativeDateSource(string $typeCode): bool
    {
        return $this->fieldTypes->has($typeCode) && $this->fieldTypes->get($typeCode) instanceof RelativeDateSourceInterface;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function earlierFieldError(array $scope): string
    {
        return $scope['earlier']
            ? (string)__('"%1": a condition can only use fields that appear earlier in the form.', $scope['label'])
            : (string)__('"%1": a condition names a field that is not in the form or cannot be routed.', $scope['label']);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function unknownOperatorError(array $scope): string
    {
        return (string)__('"%1": unknown condition operator.', $scope['label']);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function singleValueSourceError(array $scope): string
    {
        return (string)__(
            '"%1": a rule can use only one of a value, another field or a relative date, and only with an operator that compares a value.',
            $scope['label']
        );
    }
}
