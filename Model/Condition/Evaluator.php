<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Condition;

use Furan\FormBuilder\Api\Data\FormState;
use Furan\FormBuilder\Api\FieldType\RelativeDateSourceInterface;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Schema\SchemaFields;

class Evaluator
{
    public function __construct(
        private readonly OperatorPool $operators,
        private readonly Pool $fieldTypes,
        private readonly ActionPool $actions
    ) {
    }

    /**
     * Whether an item with this condition is visible: the show and hide actions of the condition, nothing else.
     *
     * @param array<string, mixed>|null $condition
     * @param array<string, string|array<int, string>> $values
     * @param array<string, array<string, mixed>> $fieldsByName source field definitions handed to operators
     * @param string|null $today Y-m-d for relative dates (see Today); null = relative date rules never match
     */
    public function evaluate(?array $condition, array $values, array $fieldsByName = [], ?string $today = null): bool
    {
        $state = new FormState($values);
        $state->begin('', true);
        $this->applyActions($condition, '', $state, $fieldsByName, $today);
        return $state->isVisible('');
    }

    /**
     * Whether the rules of a condition (or of a page skip) match; no rules never match.
     *
     * @param array<string, mixed> $condition
     * @param array<string, string|array<int, string>> $values
     * @param array<string, array<string, mixed>> $fieldsByName
     */
    public function matches(array $condition, array $values, array $fieldsByName = [], ?string $today = null): bool
    {
        return $this->matchesGroup($condition, [$values, $fieldsByName, $today], 1);
    }

    /**
     * A group beyond the level limit or without rules never matches.
     *
     * @param array<string, mixed> $group
     * @param array{0: array<string, string|array<int, string>>, 1: array<string, array<string, mixed>>, 2: ?string} $context
     */
    private function matchesGroup(array $group, array $context, int $level): bool
    {
        $rules = $group['rules'] ?? null;
        if (!is_array($rules) || $rules === [] || $level > ConditionNormalizer::MAX_LEVELS) {
            return false;
        }
        $any = ($group['match'] ?? 'all') === 'any';
        foreach ($rules as $rule) {
            $matched = is_array($rule) && (ConditionNormalizer::isGroup($rule)
                ? $this->matchesGroup($rule, $context, $level + 1)
                : $this->evaluateRule($rule, $context));
            if ($matched === $any) {
                return $any;
            }
        }
        return !$any;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, string|array<int, string>> $values
     * @return array<string, bool> item id => visible
     */
    public function computeVisibility(array $schema, array $values, ?string $today = null): array
    {
        return $this->computeState($schema, $values, $today)->visibility();
    }

    /**
     * One ordered pass over the form: visibility, required, hidden options and set values. Later conditions see
     * the answers of hidden fields as empty and the values earlier set_value actions wrote.
     *
     * @param array<string, mixed> $schema
     * @param array<string, string|array<int, string>> $values
     * @param string[] $exemptRequired field names that are never required (stored files, newsletter consent)
     */
    public function computeState(array $schema, array $values, ?string $today = null, array $exemptRequired = []): FormState
    {
        $state = new FormState($values);
        $pages = $schema['pages'] ?? [];
        $fieldsByName = $this->fieldsByName($schema);
        $pass = [$fieldsByName, $today, $exemptRequired];
        $skipUntil = null;
        foreach ($pages as $index => $page) {
            $id = (string)$page['id'];
            $skipped = $skipUntil !== null && $id !== $skipUntil;
            if ($id === $skipUntil) {
                $skipUntil = null;
            }
            $this->walk($page, !$skipped, $state, $pass);
            if (isset($page['skip']) && $state->isVisible($id)) {
                $skipUntil = $this->skipTarget($page, array_slice($pages, $index + 1), $state, $pass);
            }
        }
        return $state;
    }

    /**
     * A matched skip hides the pages strictly between the page and its target.
     *
     * @param array<string, mixed> $page
     * @param array<int, array<string, mixed>> $laterPages
     * @param array{0: array<string, array<string, mixed>>, 1: ?string, 2: string[]} $pass
     */
    private function skipTarget(array $page, array $laterPages, FormState $state, array $pass): ?string
    {
        $skip = $page['skip'] ?? null;
        $target = is_array($skip) ? ($skip['target'] ?? null) : null;
        if (!is_string($target) || !in_array($target, array_column($laterPages, 'id'), true)) {
            return null;
        }
        return $this->matches($skip, $state->values(), $pass[0], $pass[1]) ? $target : null;
    }

    /**
     * @param array<string, mixed> $item
     * @param array{0: array<string, array<string, mixed>>, 1: ?string, 2: string[]} $pass fields by name, today, exempt
     */
    private function walk(array $item, bool $parentVisible, FormState $state, array $pass): void
    {
        [$fieldsByName, $today, $exempt] = $pass;
        $id = (string)$item['id'];
        $isField = ($item['kind'] ?? '') === 'field';
        $name = (string)($item['name'] ?? '');
        $state->begin($id, $parentVisible, $isField && !empty($item['required']));
        if ($parentVisible) {
            $this->applyActions($item['condition'] ?? null, $id, $state, $fieldsByName, $today);
        }
        if (!$state->isVisible($id)) {
            $state->discard($id);
            if ($isField) {
                $state->forget($name);
            }
        } elseif ($isField) {
            $this->settleField($item, $state);
            if (in_array($name, $exempt, true)) {
                $state->exempt($id);
            }
        }
        foreach ($item['items'] ?? [] as $child) {
            $this->walk($child, $state->isVisible($id), $state, $pass);
        }
    }

    /**
     * @param array<string, mixed>|null $condition
     * @param array<string, array<string, mixed>> $fieldsByName
     */
    private function applyActions(?array $condition, string $id, FormState $state, array $fieldsByName, ?string $today): void
    {
        $rules = is_array($condition) ? ($condition['rules'] ?? []) : [];
        if (!is_array($rules) || $rules === []) {
            return;
        }
        $matched = $this->matches($condition, $state->values(), $fieldsByName, $today);
        foreach (ConditionNormalizer::actionsOf($condition) as $action) {
            if ($this->actions->has($action['action'])) {
                $this->actions->get($action['action'])->apply($state, $id, $action['param'] ?? null, $matched);
            }
        }
    }

    /**
     * A set value overwrites locked and hidden-type fields; on any other field it is only a default.
     *
     * @param array<string, mixed> $field
     */
    private function settleField(array $field, FormState $state): void
    {
        $id = (string)$field['id'];
        $value = $state->requestedValue($id);
        $type = (string)($field['type'] ?? '');
        if ($value === null || !$this->fieldTypes->has($type)) {
            return;
        }
        $instance = $this->fieldTypes->get($type);
        if ($this->fieldTypes->isConsent($type)) {
            return;
        }
        if (!empty($field['locked']) || $this->fieldTypes->isServerValue($type)) {
            $state->overwrite((string)$field['name'], $instance->isMultiValue() ? [$value] : $value);
            return;
        }
        $state->suggest($id, $value);
    }

    /**
     * @param array<string, mixed> $rule
     * @param array{0: array<string, mixed>, 1: array<string, array<string, mixed>>, 2: ?string} $context values, fields by name, today
     */
    private function evaluateRule(array $rule, array $context): bool
    {
        [$values, $fieldsByName, $today] = $context;
        $operator = $rule['operator'] ?? '';
        if (!is_string($operator) || !$this->operators->has($operator)) {
            return false;
        }
        $name = (string)($rule['field'] ?? '');
        $field = $fieldsByName[$name] ?? [];
        $answer = $this->answer($values[$name] ?? '');
        if (isset($rule['value_date'])) {
            $format = $this->relativeFormat($field);
            $expected = $format === null ? null : $this->relativeDate($rule['value_date'], $today, $format);
            if ($expected === null) {
                return false;
            }
            $expected = $this->asComparableDay($expected, $format);
            $answer = is_array($answer)
                ? array_map(fn ($v): string => $this->asComparableDay(is_scalar($v) ? (string)$v : '', $format), $answer)
                : $this->asComparableDay($answer, $format);
        } else {
            $expected = $this->expected($rule, $values);
        }
        return $this->operators->get($operator)->evaluate($answer, $expected, $field);
    }

    /**
     * An object answer (an array with a string key) counts as one empty value, like in the browser.
     *
     * @return string|array<int|string, mixed>
     */
    private function answer(mixed $value): string|array
    {
        if (is_array($value)) {
            return array_filter(array_keys($value), 'is_string') === [] ? $value : '';
        }
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * A compared field's list answer becomes one element per line, like an is_one_of list.
     *
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $values
     */
    private function expected(array $rule, array $values): string
    {
        if (isset($rule['value_field'])) {
            $other = is_string($rule['value_field']) ? $this->answer($values[$rule['value_field']] ?? '') : '';
            return is_array($other)
                ? implode("\n", array_map(static fn ($v): string => is_scalar($v) ? (string)$v : '', $other))
                : $other;
        }
        $expected = $rule['value'] ?? '';
        return is_scalar($expected) ? (string)$expected : '';
    }

    /**
     * @param array<string, mixed> $field
     */
    private function relativeFormat(array $field): ?string
    {
        $type = $field['type'] ?? null;
        if (!is_string($type) || !$this->fieldTypes->has($type)) {
            return null;
        }
        $source = $this->fieldTypes->get($type);
        return $source instanceof RelativeDateSourceInterface ? $source->relativeDateFormat() : null;
    }

    /** UTC day arithmetic on the given Y-m-d, so the browser can mirror it without DST effects. */
    private function relativeDate(mixed $spec, ?string $today, string $format): ?string
    {
        $days = is_array($spec) ? ($spec['days'] ?? null) : null;
        if (!is_int($days) || abs($days) > ConditionNormalizer::MAX_RELATIVE_DAYS || $today === null || !$this->isDay($today)) {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        $date = new \DateTimeImmutable($today . ' 00:00:00', $utc);
        return $date->modify(sprintf('%+d days', $days))->format($format);
    }

    /** Months compare as their first day, datetime answers by their date part; anything else stays as typed. */
    private function asComparableDay(string $value, string $format): string
    {
        if ($format === 'Y-m') {
            return preg_match('/^\d{4}-\d{2}$/D', $value) === 1 ? $value . '-01' : $value;
        }
        return preg_match('/^(\d{4}-\d{2}-\d{2})T([01]\d|2[0-3]):[0-5]\d$/D', $value, $m) === 1 ? $m[1] : $value;
    }

    private function isDay(string $day): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $day, $m) === 1 && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, array<string, mixed>>
     */
    public function fieldsByName(array $schema): array
    {
        return SchemaFields::byName($schema);
    }
}
