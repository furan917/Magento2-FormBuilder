<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Schema;

use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Api\FieldType\ConditionSourceInterface;
use Furan\FormBuilder\Api\FieldType\ConsentTypeInterface;
use Furan\FormBuilder\Api\FieldTypeInterface;
use Furan\FormBuilder\Model\Condition\ConditionNormalizer;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Prefill\Sources;
use Furan\FormBuilder\Model\Validation\Mask;
use Furan\FormBuilder\Model\Validation\PatternMatcher;
use Furan\FormBuilder\Model\Validation\Rule\Preset;
use Furan\FormBuilder\Model\Validation\PresetFit;
use Furan\FormBuilder\Model\Validation\RulePool;

/**
 * @phpstan-type StoredField array{id: string, name: string, type: string, ...}
 */
class Normalizer
{
    public const SCHEMA_VERSION = 1;

    private const MAX_PAGES = 20;
    private const MAX_ITEMS = 200;
    private const MAX_NAME_LENGTH = 64;
    private const ID_PATTERN = '/^%s_[a-z0-9]{4,16}$/D';
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';
    private const CSS_CLASS_PATTERN = '/^[a-zA-Z0-9_ -]{0,100}$/D';
    private const WIDTHS = ['full', 'two_thirds', 'half', 'third'];
    private const NOTICE_STYLES = ['info', 'success', 'warning'];
    private const AUTOCOMPLETE = [
        'name', 'given-name', 'family-name', 'email', 'tel', 'organization', 'street-address',
        'address-line1', 'address-line2', 'address-level1', 'address-level2', 'postal-code',
        'country', 'country-name', 'bday', 'url', 'off',
    ];
    private const MAX_RULES = 20;
    private const MAX_OPTIONS = 500;
    private const QUERY_PARAM_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

    /** @var array<string, true> */
    private array $usedIds = [];
    /** @var array<string, true> */
    private array $usedNames = [];
    private int $itemCount = 0;
    /** @var array<int, array{label: string, name: string}> newsletter fields and the email field they name */
    private array $emailFieldRefs = [];
    /** @phpstan-var array{ids: array<string, StoredField>, names: array<string, StoredField>} stored fields of types that are not registered */
    private array $storedUnknown = ['ids' => [], 'names' => []];

    /** @var array<string, string> field name => type code, for fields usable as condition sources so far */
    private array $sourceTypes = [];
    /** @var array<string, array<int, array<string, mixed>>> item id => condition rules of the saved schema */
    private array $storedConditionRules = [];
    /** @var array<string, array<int, array<string, mixed>>> item id => condition actions of the saved schema */
    private array $storedConditionActions = [];
    /** @var array<string, array<int, array<string, mixed>>> page id => skip rules of the saved schema */
    private array $storedSkipRules = [];
    /** @var array<int, array{id: string, label: string, target: string}> page skips awaiting the target check */
    private array $pendingSkips = [];
    /** @var array<string, string> field id => prefill source code of the saved schema */
    private array $storedPrefill = [];

    public function __construct(
        private readonly Pool $fieldTypes,
        private readonly RulePool $rulePool,
        private readonly PatternMatcher $patternMatcher,
        private readonly SettingValues $settingValues,
        private readonly PersonalData $personalData,
        private readonly Sources $prefillSources,
        private readonly ConditionNormalizer $conditions,
        private readonly FieldChecks $checks,
        private readonly PresetFit $presetFit
    ) {
    }

    /**
     * @throws InvalidSchemaException
     * @param array<string, mixed> $raw
     * @param array<string, mixed>|null $stored
     * @return array<string, mixed>
     */
    public function normalize(array $raw, ?array $stored = null): array
    {
        $this->storedUnknown = $this->indexUnknownFields($stored['pages'] ?? []);
        $this->usedIds = [];
        $this->usedNames = [];
        $this->sourceTypes = [];
        $this->storedConditionRules = $this->indexConditionRules($stored['pages'] ?? []);
        $this->storedConditionActions = $this->indexConditionActions($stored['pages'] ?? []);
        $this->storedSkipRules = $this->indexSkipRules($stored['pages'] ?? []);
        $this->pendingSkips = [];
        $this->storedPrefill = $this->indexPrefill($stored['pages'] ?? []);
        $this->itemCount = 0;
        $this->emailFieldRefs = [];
        $errors = [];

        $rawPages = $raw['pages'] ?? [];
        $rawPages = is_array($rawPages) ? array_values($rawPages) : [];
        if ($rawPages === []) {
            $rawPages = [[]];
        }
        if (count($rawPages) > self::MAX_PAGES) {
            $errors[] = (string)__('A form can have at most %1 pages.', self::MAX_PAGES);
            $rawPages = array_slice($rawPages, 0, self::MAX_PAGES);
        }

        $pages = [];
        $earlierNames = [];
        foreach ($rawPages as $rawPage) {
            $pages[] = $this->normalizePage(is_array($rawPage) ? $rawPage : [], $earlierNames, $errors);
        }

        array_push($errors, ...$this->emailFieldErrors(), ...$this->skipTargetErrors($pages));
        if ($errors !== []) {
            throw new InvalidSchemaException($errors);
        }
        return ['version' => self::SCHEMA_VERSION, 'pages' => $pages];
    }

    /**
     * @param array<string, mixed> $raw
     * @param string[] $earlierNames
     * @param string[] $errors
     * @return array<string, mixed>
     */
    private function normalizePage(array $raw, array &$earlierNames, array &$errors): array
    {
        $id = $this->resolveId($raw['id'] ?? null, 'p');
        $title = $this->cleanString($raw['title'] ?? '', 255);
        $condition = $this->normalizeCondition($raw['condition'] ?? null, $id, $title ?: $id, $errors, ['kind' => 'page']);

        $items = [];
        $rawItems = is_array($raw['items'] ?? null) ? array_values($raw['items']) : [];
        foreach ($rawItems as $rawItem) {
            $item = $this->normalizeItem($rawItem, true, $earlierNames, $errors);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        // The page's own fields are known now, so a skip may use them as well as every earlier field.
        $skip = $this->normalizeSkip($raw['skip'] ?? null, $id, $title ?: $id, $errors);
        return ['id' => $id, 'title' => $title, 'condition' => $condition, 'skip' => $skip, 'items' => $items];
    }

    /**
     * @param string[] $errors
     * @return array{match: string, rules: array<int, array<string, mixed>>, target: string}|null
     */
    private function normalizeSkip(mixed $raw, string $pageId, string $label, array &$errors): ?array
    {
        $core = $this->conditions->normalizeRules(
            $raw,
            $this->sourceTypes,
            $this->storedSkipRules[$pageId] ?? [],
            $label,
            $errors
        );
        if ($core === null) {
            return null;
        }
        $target = is_string($raw['target'] ?? null) ? $raw['target'] : '';
        $this->pendingSkips[] = ['id' => $pageId, 'label' => $label, 'target' => $target];
        return $core + ['target' => $target];
    }

    /**
     * Checked once every page id is final: a skip leads to a later page.
     *
     * @param array<int, array<string, mixed>> $pages
     * @return string[]
     */
    private function skipTargetErrors(array $pages): array
    {
        $order = array_flip(array_column($pages, 'id'));
        $errors = [];
        foreach ($this->pendingSkips as $skip) {
            $from = $order[$skip['id']] ?? -1;
            $to = $order[$skip['target']] ?? -1;
            if ($to <= $from) {
                $errors[] = (string)__('"%1": a page can only skip ahead to a later page.', $skip['label']);
            }
        }
        return $errors;
    }

    /**
     * @param string[] $earlierNames
     * @param string[] $errors
     * @return array<string, mixed>|null
     */
    private function normalizeItem(mixed $raw, bool $allowSection, array &$earlierNames, array &$errors): ?array
    {
        if (!is_array($raw)) {
            $errors[] = (string)__('Each item must be an object.');
            return null;
        }
        if (++$this->itemCount > self::MAX_ITEMS) {
            if ($this->itemCount === self::MAX_ITEMS + 1) {
                $errors[] = (string)__('A form can have at most %1 items.', self::MAX_ITEMS);
            }
            return null;
        }
        $kind = $raw['kind'] ?? null;
        if ($kind === 'section' && !$allowSection) {
            $errors[] = (string)__('Sections cannot be nested inside other sections.');
            return null;
        }
        $item = match ($kind) {
            'field' => $this->normalizeField($raw, $earlierNames, $errors),
            'content' => $this->normalizeContent($raw, $earlierNames, $errors),
            'section' => $this->normalizeSection($raw, $earlierNames, $errors),
            default => null,
        };
        if ($item === null) {
            if (!in_array($kind, ['field', 'content', 'section'], true)) {
                $errors[] = (string)__('Unknown item kind "%1".', $this->cleanString($kind, 32));
            }
            return null;
        }
        $group = $raw['source_group'] ?? null;
        if ((is_int($group) || (is_string($group) && ctype_digit($group))) && (int)$group > 0) {
            $item['source_group'] = (int)$group;
        }
        return $item;
    }

    /**
     * @param array<string, mixed> $raw
     * @param string[] $earlierNames
     * @param string[] $errors
     * @return array<string, mixed>
     */
    private function normalizeSection(array $raw, array &$earlierNames, array &$errors): array
    {
        $id = $this->resolveId($raw['id'] ?? null, 's');
        $title = $this->cleanString($raw['title'] ?? '', 255);
        $condition = $this->normalizeCondition($raw['condition'] ?? null, $id, $title ?: $id, $errors, ['kind' => 'section']);

        $items = [];
        $rawItems = is_array($raw['items'] ?? null) ? array_values($raw['items']) : [];
        foreach ($rawItems as $rawItem) {
            $item = $this->normalizeItem($rawItem, false, $earlierNames, $errors);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return [
            'id' => $id,
            'kind' => 'section',
            'title' => $title,
            'description' => $this->cleanString($raw['description'] ?? '', 1000, true),
            'condition' => $condition,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @param string[] $earlierNames
     * @param string[] $errors
     * @return array<string, mixed>|null
     */
    private function normalizeContent(array $raw, array $earlierNames, array &$errors): ?array
    {
        $type = $raw['type'] ?? null;
        if (!is_string($type) || !ContentTypes::has($type)) {
            $errors[] = (string)__('Unknown content type "%1".', $this->cleanString($type, 32));
            return null;
        }
        $id = $this->resolveId($raw['id'] ?? null, 'c');
        $item = ['id' => $id, 'kind' => 'content', 'type' => $type];
        if ($type === 'heading') {
            $item['text'] = $this->cleanString($raw['text'] ?? '', 255);
            $item['level'] = $this->clampInt($raw['level'] ?? null, 2, 4, 2);
        } elseif ($type === 'paragraph') {
            $item['text'] = $this->cleanString($raw['text'] ?? '', 5000, true);
        } elseif ($type === 'rich_text') {
            $item['text'] = $this->cleanString($raw['text'] ?? '', 5000, true);
        } elseif ($type === 'notice') {
            $item['text'] = $this->cleanString($raw['text'] ?? '', 1000, true);
            $style = $raw['style'] ?? null;
            $item['style'] = is_string($style) && in_array($style, self::NOTICE_STYLES, true) ? $style : 'info';
            $item['title'] = $this->cleanString($raw['title'] ?? '', 255);
        } elseif ($type === 'summary') {
            $item['title'] = $this->cleanString($raw['title'] ?? '', 255) ?: (string)__('Review your answers');
        }
        $item['width'] = $this->normalizeWidth($raw['width'] ?? null);
        $item['condition'] = $this->normalizeCondition($raw['condition'] ?? null, $id, $id, $errors, ['kind' => 'content']);
        return $item;
    }

    /**
     * @param array<string, mixed> $raw
     * @param string[] $earlierNames
     * @param string[] $errors
     * @return array<string, mixed>|null
     */
    private function normalizeField(array $raw, array &$earlierNames, array &$errors): ?array
    {
        $typeCode = is_string($raw['type'] ?? null) ? $raw['type'] : '';
        if (!$this->fieldTypes->isUsable($typeCode)) {
            $kept = $this->keepStoredField($raw, $typeCode, $earlierNames, $errors);
            if ($kept !== null) {
                return $kept;
            }
        }
        $id = $this->resolveId($raw['id'] ?? null, 'f');
        $label = $this->cleanString($raw['label'] ?? '', 255);
        $name = is_string($raw['name'] ?? null) ? trim($raw['name']) : '';
        $display = $label !== '' ? $label : ($name !== '' ? $name : $id);

        $valid = true;
        if (!$this->fieldTypes->isUsable($typeCode)) {
            $errors[] = (string)__('Field "%1" has an unknown type "%2".', $display, $this->cleanString($typeCode, 32));
            $valid = false;
        }
        if ($label === '') {
            $errors[] = (string)__('Field "%1" needs a label.', $display);
            $valid = false;
        }
        if (!$this->isNameValid($name, $display, $errors)) {
            $valid = false;
        }
        if (!$valid) {
            return null;
        }

        $this->usedNames[$name] = true;
        $type = $this->fieldTypes->get($typeCode);
        $settings = $this->normalizeSettings($raw['settings'] ?? null, $type, $label, $errors);
        $options = $this->normalizeOptions($raw['options'] ?? null, $type, $label, $errors);
        $rules = $this->normalizeRules($raw['rules'] ?? null, $type, $label, $earlierNames, $errors);
        $this->checkMaskAgainstFormats((string)($settings['mask'] ?? ''), $rules, $label, $errors);
        if (isset($settings['email_field'])) {
            $this->emailFieldRefs[] = ['label' => $label, 'name' => (string)$settings['email_field']];
        }
        $this->checks->bounds($type, ['settings' => $settings, 'rules' => $rules, 'options' => $options], $label, $errors);
        $isConsent = $type instanceof ConsentTypeInterface;
        $requested = $this->toBool($raw['required'] ?? false, false);
        if ($isConsent && $requested) {
            $errors[] = (string)__('Field "%1" cannot be required: consent must be freely given.', $label);
        } elseif ($this->fieldTypes->isServerValue($typeCode) && $requested) {
            $errors[] = (string)__('Field "%1" cannot be required: its value is set by the server, not the visitor.', $label);
        }
        $field = [
            'id' => $id,
            'kind' => 'field',
            'type' => $typeCode,
            'name' => $name,
            'label' => $label,
            'placeholder' => $this->cleanString($raw['placeholder'] ?? '', 255),
            'help' => $this->cleanString($raw['help'] ?? '', 1000, true),
            'default' => $isConsent ? '' : $this->checks->defaultValue(
                $type,
                ['settings' => $settings, 'rules' => $rules, 'options' => $options],
                $this->cleanString($raw['default'] ?? '', FieldChecks::MAX_DEFAULT_LENGTH + 1),
                $label,
                $errors
            ),
            'width' => $this->normalizeWidth($raw['width'] ?? null),
            'required' => !$isConsent && $requested,
            'rules' => $rules,
            'options' => $options,
            'settings' => $settings,
            'condition' => $this->normalizeCondition(
                $raw['condition'] ?? null,
                $id,
                $label,
                $errors,
                ['kind' => 'field', 'type' => $type, 'field' => ['name' => $name, 'options' => $options, 'settings' => $settings]]
            ),
            'draft' => $this->normalizeDraft($raw['draft'] ?? true, $type, $typeCode),
            'autocomplete' => $this->normalizeAutocomplete($raw['autocomplete'] ?? ''),
            'css_class' => $this->normalizeCssClass($raw['css_class'] ?? ''),
        ];
        $field['personal'] = $this->personalData->isPersonal(
            ['type' => $typeCode, 'autocomplete' => $field['autocomplete'], 'personal' => $raw['personal'] ?? null]
        );
        // A prefilled tick would be consent the visitor never gave.
        $field['prefill'] = $isConsent ? '' : $this->normalizePrefill($raw['prefill'] ?? null, $typeCode, $id);
        // Product values are server-resolved, so a client-posted value is never trusted.
        $field['locked'] = $field['prefill'] !== ''
            && ($this->prefillSources->isProduct($field['prefill']) || $this->toBool($raw['locked'] ?? false, false));
        if (!($type instanceof ConditionSourceInterface) || $type->canBeConditionSource()) {
            $earlierNames[] = $name;
            $this->sourceTypes[$name] = $typeCode;
        }
        return $field;
    }

    /**
     * Copies the saved field (never the posted one) of a type whose module is disabled, matched by id then name.
     * @param array<string, mixed> $raw
     * @param string[] $earlierNames
     * @param string[] $errors
     * @return array<string, mixed>|null
     */
    private function keepStoredField(array $raw, string $typeCode, array &$earlierNames, array &$errors): ?array
    {
        $id = is_string($raw['id'] ?? null) ? $raw['id'] : '';
        $name = is_string($raw['name'] ?? null) ? trim($raw['name']) : '';
        $stored = $this->storedUnknown['ids'][$id] ?? $this->storedUnknown['names'][$name] ?? null;
        if ($stored === null || $stored['type'] !== $typeCode) {
            return null;
        }
        if (isset($this->usedIds[$stored['id']]) || isset($this->usedNames[$stored['name']])) {
            $errors[] = (string)__('Field name "%1" is used more than once.', $stored['name']);
            return $stored;
        }
        $this->usedIds[$stored['id']] = true;
        $this->usedNames[$stored['name']] = true;
        $earlierNames[] = $stored['name'];
        $this->sourceTypes[$stored['name']] = $typeCode;
        return $stored;
    }

    /**
     * @param array<mixed> $containers
     * @return array{ids: array<string, StoredField>, names: array<string, StoredField>}
     */
    private function indexUnknownFields(array $containers): array
    {
        $index = ['ids' => [], 'names' => []];
        foreach ($containers as $container) {
            foreach (is_array($container) ? ($container['items'] ?? []) : [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (($item['kind'] ?? '') === 'section') {
                    $nested = $this->indexUnknownFields([$item]);
                    $index['ids'] += $nested['ids'];
                    $index['names'] += $nested['names'];
                } elseif (($item['kind'] ?? '') === 'field' && is_string($item['type'] ?? null)
                    && is_string($item['id'] ?? null) && is_string($item['name'] ?? null)
                    && !$this->fieldTypes->isUsable($item['type'])
                ) {
                    /** @var StoredField $item */
                    $index['ids'][$item['id']] = $item;
                    $index['names'][$item['name']] = $item;
                }
            }
        }
        return $index;
    }

    /**
     * @return string[]
     */
    private function emailFieldErrors(): array
    {
        $errors = [];
        foreach ($this->emailFieldRefs as $ref) {
            if ($ref['name'] !== '' && !$this->fieldTypes->isEmailAddress($this->sourceTypes[$ref['name']] ?? '')) {
                $errors[] = (string)__('Field "%1": "%2" must name an email field of this form.', $ref['label'], $ref['name']);
            }
        }
        return $errors;
    }

    /**
     * @param string[] $errors
     */
    private function isNameValid(string $name, string $display, array &$errors): bool
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            $errors[] = (string)__(
                'Field "%1" has an invalid name. Use lowercase letters, digits and underscores, starting with a letter (max %2 characters).',
                $display,
                self::MAX_NAME_LENGTH
            );
            return false;
        }
        if (in_array($name, ['form_key', 'form_id'], true) || str_starts_with($name, 'ffb_')) {
            $errors[] = (string)__('Field "%1" uses the reserved name "%2".', $display, $name);
            return false;
        }
        if (isset($this->usedNames[$name])) {
            $errors[] = (string)__('Field name "%1" is used more than once.', $name);
            return false;
        }
        return true;
    }

    /**
     * A mask must never make a value fail the library format it is combined with: the format's example, and the
     * example without separators when that passes the format too, must still pass after masking.
     *
     * @param array<int, array<string, mixed>> $rules
     * @param string[] $errors
     */
    private function checkMaskAgainstFormats(string $mask, array $rules, string $label, array &$errors): void
    {
        $preset = $this->rulePool->has('preset') ? $this->rulePool->get('preset') : null;
        if ($mask === '' || !$preset instanceof Preset) {
            return;
        }
        foreach ($rules as $rule) {
            $entry = $rule['rule'] === 'preset' && is_string($rule['value']) ? $preset->resolve($rule['value']) : null;
            if ($entry === null || $entry['example'] === '') {
                continue;
            }
            $bare = preg_replace('/[^\p{L}\p{N}]/u', '', $entry['example']) ?? '';
            foreach ([$entry['example'], $bare] as $sample) {
                if ($this->patternMatcher->matches($entry['pattern'], $sample)
                    && !$this->patternMatcher->matches($entry['pattern'], Mask::format($sample, $mask))
                ) {
                    $errors[] = (string)__(
                        'Field "%1": the mask would turn valid answers into ones the format "%2" rejects.',
                        $label,
                        $entry['label']
                    );
                    return;
                }
            }
        }
    }

    /**
     * @param string[] $errors
     * @return array<string, mixed>
     */
    private function normalizeSettings(mixed $raw, FieldTypeInterface $type, string $label, array &$errors): array
    {
        $problems = [];
        $spec = $type->getSettingsSpec();
        $settings = $this->settingValues->normalize(is_array($raw) ? $raw : [], $spec, $problems);
        foreach ($problems as [$kind, $key, $argument]) {
            $errors[] = $this->checks->settingProblem($kind, $key, $argument, $spec, $label) ?? match ($kind) {
                SettingValues::PROBLEM_MIN_ITEMS => (string)__('Field "%1": "%2" needs at least %3 entries.', $label, $key, $argument),
                SettingValues::PROBLEM_MASK => (string)__(
                    'Field "%1": the mask must be a preset or use only 9, a, *, spaces and - + ( ) . , / : # _ (at most %2 characters).',
                    $label,
                    $argument
                ),
                default => (string)__('Field "%1": "%2" must be a relative path or a URL on this store.', $label, $key),
            };
        }
        if ($type->isFile() && is_array($settings['extensions'] ?? null)) {
            $settings['extensions'] = $this->checks->extensions($settings['extensions'], $label, $errors);
        }
        if (isset($settings['query_param'])
            && preg_match(self::QUERY_PARAM_PATTERN, (string)$settings['query_param']) !== 1
        ) {
            $settings['query_param'] = '';
        }
        return $settings;
    }

    private function normalizeDraft(mixed $raw, FieldTypeInterface $type, string $typeCode): bool
    {
        if ($type->isFile() || $this->fieldTypes->isServerValue($typeCode) || $type instanceof ConsentTypeInterface) {
            return false;
        }
        return $this->toBool($raw, true);
    }

    private function normalizePrefill(mixed $raw, string $typeCode, string $fieldId): string
    {
        if (!is_string($raw)) {
            return '';
        }
        // A source whose module is disabled stays only on the field that already holds it.
        if (!$this->prefillSources->isValid($raw)) {
            return $raw !== '' && ($this->storedPrefill[$fieldId] ?? null) === $raw ? $raw : '';
        }
        return $this->prefillSources->suits($raw, $typeCode) ? $raw : '';
    }

    private function normalizeWidth(mixed $raw): string
    {
        return is_string($raw) && in_array($raw, self::WIDTHS, true) ? $raw : 'full';
    }

    private function normalizeAutocomplete(mixed $raw): string
    {
        return is_string($raw) && in_array($raw, self::AUTOCOMPLETE, true) ? $raw : '';
    }

    private function normalizeCssClass(mixed $raw): string
    {
        return is_string($raw) && preg_match(self::CSS_CLASS_PATTERN, $raw) === 1 ? $raw : '';
    }

    private function resolveId(mixed $candidate, string $prefix): string
    {
        $valid = is_string($candidate)
            && preg_match(sprintf(self::ID_PATTERN, $prefix), $candidate) === 1
            && !isset($this->usedIds[$candidate]);
        if ($valid) {
            $id = $candidate;
        } else {
            do {
                $id = $prefix . '_' . bin2hex(random_bytes(4));
            } while (isset($this->usedIds[$id]));
        }
        $this->usedIds[$id] = true;
        return $id;
    }

    private function cleanString(mixed $value, int $maxLength, bool $keepNewlines = false): string
    {
        return $this->settingValues->cleanString($value, $maxLength, $keepNewlines);
    }

    private function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        return is_numeric($value) ? max($min, min($max, (int)$value)) : $default;
    }

    private function toBool(mixed $value, bool $default): bool
    {
        return $this->settingValues->toBool($value, $default);
    }

    /**
     * @param string[] $earlierNames
     * @param string[] $errors
     * @return list<array<string, mixed>>
     */
    private function normalizeRules(
        mixed $raw,
        FieldTypeInterface $type,
        string $fieldLabel,
        array $earlierNames,
        array &$errors
    ): array {
        if (!is_array($raw)) {
            return [];
        }
        $raw = array_values($raw);
        if (count($raw) > self::MAX_RULES) {
            $errors[] = (string)__('Field "%1" can have at most %2 rules.', $fieldLabel, self::MAX_RULES);
            $raw = array_slice($raw, 0, self::MAX_RULES);
        }
        $rules = [];
        foreach ($raw as $rawRule) {
            $code = is_array($rawRule) && is_string($rawRule['rule'] ?? null) ? $rawRule['rule'] : '';
            if (!in_array($code, $type->getAllowedRules(), true) || !$this->rulePool->has($code)) {
                $errors[] = (string)__(
                    'Field "%1": the rule "%2" is not available for this field type.',
                    $fieldLabel,
                    $this->cleanString($code, 32)
                );
                continue;
            }
            $value = $this->coerceRuleValue($code, $rawRule['value'] ?? null, $fieldLabel, $earlierNames, $errors);
            if ($code === 'preset' && !$this->presetFits($value, $type, $fieldLabel, $errors)) {
                continue;
            }
            // A field may use several library formats, but each other rule once.
            $key = $code === 'preset' ? $code . ':' . $value : $code;
            unset($rules[$key]);
            $rules[$key] = [
                'rule' => $code,
                'value' => $value,
                'message' => $this->cleanString($rawRule['message'] ?? '', 255),
            ];
        }
        return array_values($rules);
    }

    /**
     * The builder offers a type only the library formats of its groups; saved formats fit every type.
     *
     * @param string[] $errors
     */
    private function presetFits(mixed $ref, FieldTypeInterface $type, string $fieldLabel, array &$errors): bool
    {
        $preset = $this->rulePool->get('preset');
        $entry = is_string($ref) && $preset instanceof Preset ? $preset->resolve($ref) : null;
        if ($entry === null || $this->presetFit->fitsType($entry['group_code'], $type)) {
            return true;
        }
        $errors[] = (string)__('Field "%1": the format "%2" does not apply to this field type.', $fieldLabel, $entry['label']);
        return false;
    }

    /**
     * @param string[] $earlierNames
     * @param string[] $errors
     */
    private function coerceRuleValue(
        string $code,
        mixed $value,
        string $fieldLabel,
        array $earlierNames,
        array &$errors
    ): int|float|string|null {
        $valid = true;
        $result = null;
        switch ($this->rulePool->get($code)->getValueType()) {
            case 'none':
                return null;
            case 'int':
                $valid = is_numeric($value) && is_finite((float)$value);
                $result = $valid ? max($code === 'max_length' ? 1 : 0, (int)$value) : null;
                break;
            case 'number':
                $valid = is_numeric($value) && is_finite((float)$value);
                $result = $valid ? (float)$value : null;
                break;
            case 'date':
                $valid = is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) === 1
                    && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
                $result = $valid ? $value : null;
                break;
            case 'regex':
                if (is_string($value) && $this->patternMatcher->isSafe($value)) {
                    return $value;
                }
                $errors[] = (string)__('Field "%1": the pattern is invalid or too complex.', $fieldLabel);
                return null;
            case 'preset':
                $rule = $this->rulePool->get($code);
                if (is_string($value) && $rule instanceof Preset && $rule->resolve($value) !== null) {
                    return $value;
                }
                $errors[] = (string)__(
                    'Field "%1": the format rule "%2" does not exist.',
                    $fieldLabel,
                    is_scalar($value) ? $this->cleanString((string)$value, 80) : ''
                );
                return null;
            case 'field':
                $valid = is_string($value) && in_array($value, $earlierNames, true);
                if (!$valid) {
                    $errors[] = (string)__(
                        'Field "%1": the rule "%2" must point at a field that appears earlier in the form.',
                        $fieldLabel,
                        $code
                    );
                }
                return $valid ? $value : null;
            default:
                $result = is_scalar($value) ? $this->cleanString($value, 255) : null;
        }
        if (!$valid) {
            $errors[] = (string)__('Field "%1": the value for rule "%2" is invalid.', $fieldLabel, $code);
        }
        return $result;
    }

    /**
     * @param string[] $errors
     * @param array<string, mixed> $target
     * @return array<string, mixed>|null
     */
    private function normalizeCondition(mixed $raw, string $itemId, string $itemLabel, array &$errors, array $target): ?array
    {
        return $this->conditions->normalize(
            $raw,
            $this->sourceTypes,
            $this->storedConditionRules[$itemId] ?? [],
            $itemLabel,
            $errors,
            $target + ['stored_actions' => $this->storedConditionActions[$itemId] ?? []]
        );
    }

    /**
     * @param array<mixed> $containers
     * @return array<string, array<int, array<string, mixed>>> item id => condition rules
     */
    private function indexConditionRules(array $containers): array
    {
        $index = [];
        foreach ($containers as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (is_string($item['id'] ?? null) && is_array($item['condition'] ?? null)) {
                $index[$item['id']] = $this->conditions->flatten($item['condition']);
            }
            $index += $this->indexConditionRules(is_array($item['items'] ?? null) ? $item['items'] : []);
        }
        return $index;
    }

    /**
     * @param array<int, mixed> $containers
     * @return array<string, array<int, array<string, mixed>>> item id => condition actions
     */
    private function indexConditionActions(array $containers): array
    {
        $index = [];
        foreach ($containers as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (is_string($item['id'] ?? null) && is_array($item['condition'] ?? null)) {
                $index[$item['id']] = ConditionNormalizer::actionsOf($item['condition']);
            }
            $index += $this->indexConditionActions(is_array($item['items'] ?? null) ? $item['items'] : []);
        }
        return $index;
    }

    /**
     * @param array<int, mixed> $pages
     * @return array<string, array<int, array<string, mixed>>> page id => skip rules
     */
    private function indexSkipRules(array $pages): array
    {
        $index = [];
        foreach ($pages as $page) {
            if (is_array($page) && is_string($page['id'] ?? null) && is_array($page['skip'] ?? null)) {
                $index[$page['id']] = $this->conditions->flatten($page['skip']);
            }
        }
        return $index;
    }

    /**
     * @param array<mixed> $containers
     * @return array<string, string>
     */
    private function indexPrefill(array $containers): array
    {
        $index = [];
        foreach ($containers as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (is_string($item['id'] ?? null) && is_string($item['prefill'] ?? null) && $item['prefill'] !== '') {
                $index[$item['id']] = $item['prefill'];
            }
            $index += $this->indexPrefill(is_array($item['items'] ?? null) ? $item['items'] : []);
        }
        return $index;
    }

    /**
     * @param string[] $errors
     * @return list<array<string, mixed>>
     */
    private function normalizeOptions(mixed $raw, FieldTypeInterface $type, string $fieldLabel, array &$errors): array
    {
        if (!$type->hasOptions()) {
            return [];
        }
        $raw = is_array($raw) ? array_values($raw) : [];
        if ($raw === []) {
            $errors[] = (string)__('Field "%1" needs at least one option.', $fieldLabel);
            return [];
        }
        if (count($raw) > self::MAX_OPTIONS) {
            $errors[] = (string)__('Field "%1" can have at most %2 options.', $fieldLabel, self::MAX_OPTIONS);
            $raw = array_slice($raw, 0, self::MAX_OPTIONS);
        }
        $options = [];
        $seen = [];
        foreach ($raw as $index => $rawOption) {
            $rawOption = is_array($rawOption) ? $rawOption : [];
            $label = $this->cleanString($rawOption['label'] ?? '', 255);
            if ($label === '') {
                $errors[] = (string)__('Field "%1": every option needs a label.', $fieldLabel);
                continue;
            }
            $value = $this->cleanString($rawOption['value'] ?? '', 255);
            if ($value === '') {
                $value = $this->slugify($label, $index + 1);
            }
            if (isset($seen[$value])) {
                $errors[] = (string)__('Field "%1": the option value "%2" is used more than once.', $fieldLabel, $value);
                continue;
            }
            $seen[$value] = true;
            $option = ['label' => $label, 'value' => $value];
            $description = $this->cleanString($rawOption['description'] ?? '', 255);
            if ($description !== '') {
                $option['description'] = $description;
            }
            $options[] = $option;
        }
        return $options;
    }

    private function slugify(string $label, int $position): string
    {
        $slug = preg_replace('/\s+/u', '_', mb_strtolower($label)) ?? '';
        $slug = preg_replace('/[^a-z0-9_-]/', '', $slug) ?? '';
        return mb_substr($slug, 0, 255) ?: 'option_' . $position;
    }
}
