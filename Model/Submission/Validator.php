<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Model\Condition\Evaluator;
use Furan\FormBuilder\Model\Condition\Today;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Schema\SchemaFields;
use Furan\FormBuilder\Model\Validation\Rule\Preset;
use Furan\FormBuilder\Model\Validation\RulePool;
use Psr\Log\LoggerInterface;

class Validator
{
    /** @var array<string, true> */
    private array $warnedTypes = [];

    public function __construct(
        private readonly Pool $fieldTypes,
        private readonly RulePool $rules,
        private readonly Evaluator $evaluator,
        private readonly LoggerInterface $logger,
        private readonly Today $today
    ) {
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $postedFields
     * @param array<string, array<string, mixed>> $files
     * @param string[] $exemptRequired further field names that are never required (stored files, newsletter consent)
     */
    public function validate(
        array $schema,
        array $postedFields,
        array $files,
        int $storeId = 0,
        array $exemptRequired = []
    ): ValidationResult
    {
        $fields = $this->collectFields($schema, $storeId);
        $normalized = [];
        foreach ($fields as $field) {
            // The visitor has no control to answer a server-value field with.
            if ($this->fieldTypes->isServerValue($field['type'])) {
                $exemptRequired[] = $field['name'];
            }
            $type = $this->fieldTypes->get($field['type']);
            if ($type->isFile()) {
                continue;
            }
            $raw = $postedFields[$field['name']] ?? null;
            $value = $type->normalizeValue($raw, $field);
            $normalized[$field['name']] = $value ?? ($type->isMultiValue() ? [] : '');
        }

        /** @var array<string, string|array<int, string>> $normalized multi-value types normalise to string lists */
        $state = $this->evaluator->computeState($schema, $normalized, $this->today->forStore($storeId), $exemptRequired);
        $visibility = $state->visibility();
        $answers = $state->values();
        $values = [];
        foreach ($fields as $field) {
            if (($visibility[$field['id']] ?? false) && array_key_exists($field['name'], $answers)) {
                $values[$field['name']] = $answers[$field['name']];
            }
        }

        $errors = [];
        $acceptedFiles = [];
        $unansweredFiles = [];
        $context = ['values' => $values, 'store_id' => $storeId];
        foreach ($fields as $field) {
            if (!($visibility[$field['id']] ?? false)) {
                continue;
            }
            $name = $field['name'];
            $type = $this->fieldTypes->get($field['type']);
            if ($type->isFile()) {
                $upload = $files[$name] ?? null;
                if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $acceptedFiles[$name] = $upload;
                    continue;
                }
                $unansweredFiles[] = $name;
                if ($state->isRequired($field['id'])) {
                    $errors[$name] = $this->requiredMessage();
                }
                continue;
            }
            $value = $values[$name];
            if ($value === '' || $value === []) {
                if ($state->isRequired($field['id'])) {
                    $errors[$name] = $this->requiredMessage();
                }
                continue;
            }
            $message = $this->hiddenOption($value, $state->hiddenOptions()[$field['id']] ?? [])
                ?? $type->validateValue($value, $field, $context);
            $message = $message === null
                ? $this->checkRules($value, $field, $context)
                : $this->formatMessage($field, $value) ?? $message;
            if ($message !== null) {
                $errors[$name] = $message;
            }
        }

        return new ValidationResult($values, $errors, $acceptedFiles, $unansweredFiles);
    }

    /**
     * @param string|array<int, string> $value
     * @param string[] $hidden
     */
    private function hiddenOption(string|array $value, array $hidden): ?string
    {
        if ($hidden === [] || array_intersect(is_array($value) ? $value : [$value], $hidden) === []) {
            return null;
        }
        return (string)__('This option is not available.');
    }

    /**
     * When the answer fails the type check and also breaks the field's first library format, that format's message
     * describes the expected answer better than the type's generic one.
     *
     * @param array<string, mixed> $field
     * @param string|array<int|string, mixed> $value
     */
    private function formatMessage(array $field, string|array $value): ?string
    {
        foreach ($field['rules'] ?? [] as $rule) {
            $preset = ($rule['rule'] ?? '') === 'preset' && $this->rules->has('preset') ? $this->rules->get('preset') : null;
            if ($preset instanceof Preset && is_string($rule['value'] ?? null) && $preset->resolve($rule['value']) !== null) {
                if ($preset->validate($value, $rule['value'], [])) {
                    return null;
                }
                $custom = (string)($rule['message'] ?? '');
                return $custom !== '' ? $custom : $preset->getDefaultMessage($rule['value']);
            }
        }
        return null;
    }

    /**
     * @param string|array<int|string, mixed> $value
     * @param array<string, mixed> $field
     * @param array<string, mixed> $context
     */
    private function checkRules(string|array $value, array $field, array $context): ?string
    {
        foreach ($field['rules'] ?? [] as $rule) {
            $code = (string)($rule['rule'] ?? '');
            if (!$this->rules->has($code)) {
                continue;
            }
            $instance = $this->rules->get($code);
            $ruleValue = $rule['value'] ?? null;
            if (!$instance->validate($value, $ruleValue, $context)) {
                $custom = (string)($rule['message'] ?? '');
                return $custom !== '' ? $custom : $instance->getDefaultMessage($ruleValue);
            }
        }
        return null;
    }

    private function requiredMessage(): string
    {
        return (string)__('This field is required.');
    }

    /**
     * Fields in document order, sections flattened; unknown or unavailable field types are dropped.
     *
     * @param array<string, mixed> $schema
     * @return list<array<string, mixed>>
     */
    private function collectFields(array $schema, int $storeId): array
    {
        $fields = [];
        foreach (SchemaFields::all($schema) as $item) {
            $code = (string)($item['type'] ?? '');
            if ($this->fieldTypes->isUsable($code, $storeId)) {
                $fields[] = $item;
            } else {
                $this->warnUnknownType($code);
            }
        }
        return $fields;
    }

    private function warnUnknownType(string $code): void
    {
        if (!isset($this->warnedTypes[$code])) {
            $this->warnedTypes[$code] = true;
            $this->logger->warning(sprintf('Furan_FormBuilder: field type "%s" is not available; its fields are skipped.', $code));
        }
    }
}
