<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Schema;

use Furan\FormBuilder\Api\FieldType\ClientMessagesInterface;
use Furan\FormBuilder\Api\FieldType\RelativeDateSourceInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Upload\ExtensionList;
use Furan\FormBuilder\Model\Validation\Rule\Preset;
use Furan\FormBuilder\Model\Validation\RulePool;

class PublicSchema
{
    public function __construct(
        private readonly RulePool $rules,
        private readonly Pool $fieldTypes,
        private readonly Config $config,
        private readonly ExtensionList $extensionList
    ) {
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function build(array $schema): array
    {
        $schema['pages'] = array_map($this->buildContainer(...), $schema['pages'] ?? []);
        return $schema;
    }

    /**
     * @param array<string, mixed> $container
     * @return array<string, mixed>
     */
    private function buildContainer(array $container): array
    {
        unset($container['source_group']);
        if (isset($container['items'])) {
            $items = [];
            foreach ($container['items'] as $item) {
                if (($item['kind'] ?? '') !== 'field') {
                    $items[] = $this->buildContainer($item);
                } elseif ($this->fieldTypes->isUsable((string)$item['type'])) {
                    $items[] = $this->buildField($item);
                }
            }
            $container['items'] = $items;
        }
        return $container;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function buildField(array $field): array
    {
        $type = $this->fieldTypes->has((string)$field['type']) ? $this->fieldTypes->get((string)$field['type']) : null;
        unset($field['source_group']);

        $field['required_message'] = (string)__('This field is required.');
        $format = $this->firstFormat($field['rules'] ?? []);
        if ($format !== null) {
            $field['format'] = $format;
        }
        $field['rules'] = array_values(array_filter(array_map($this->resolveRule(...), $field['rules'] ?? [])));
        $field['multi'] = $type !== null && $type->isMultiValue();
        $field['file'] = $type !== null && $type->isFile();

        if ($field['file']) {
            $field['settings'] ??= [];
            $field['settings']['accept'] = $this->accept($field['settings']['extensions'] ?? []);
        }
        $message = $type?->getInvalidMessage($field);
        if ($message !== null) {
            $field['type_message'] = $message;
        }
        // The browser may only show a condition's set value as a default unless the server overwrites it too.
        $serverValue = $this->fieldTypes->isServerValue((string)$field['type']);
        if (!empty($field['locked']) || $serverValue) {
            $field['overwritable'] = true;
        }
        if ($serverValue) {
            $field['server_value'] = true;
        }
        if ($this->fieldTypes->hasSuggestionOptions((string)$field['type'])) {
            $field['suggestions'] = true;
        }
        if ($type instanceof RelativeDateSourceInterface) {
            $field['relative_date'] = $type->relativeDateFormat();
        }
        if ($type instanceof ClientMessagesInterface) {
            $field = array_merge($field, $type->getClientMessages($field));
        }
        return $field;
    }

    /**
     * The field's first library format; the browser prefers its message over the type's when the answer breaks both.
     *
     * @return array{pattern: string, message: string}|null
     * @param array<int, array<string, mixed>> $rules
     */
    private function firstFormat(array $rules): ?array
    {
        foreach ($rules as $rule) {
            if (($rule['rule'] ?? '') === 'preset') {
                $resolved = $this->resolveRule($rule);
                if ($resolved !== null) {
                    return ['pattern' => (string)$resolved['value'], 'message' => (string)$resolved['message']];
                }
            }
        }
        return null;
    }

    /**
     * Library formats reach the browser as plain `pattern` rules, so PHP and JS run the same regex.
     * @param array<string, mixed> $rule
     * @return array<string, mixed>|null
     */
    private function resolveRule(array $rule): ?array
    {
        if (($rule['rule'] ?? '') === 'preset') {
            $preset = $this->rules->has('preset') ? $this->rules->get('preset') : null;
            $entry = $preset instanceof Preset && is_string($rule['value'] ?? null) ? $preset->resolve($rule['value']) : null;
            if ($entry === null) {
                return null;
            }
            $message = trim((string)($rule['message'] ?? ''));
            return ['rule' => 'pattern', 'value' => $entry['pattern'], 'message' => $message !== '' ? $message : $entry['message']];
        }
        $message = trim((string)($rule['message'] ?? ''));
        if ($message === '' && $this->rules->has((string)$rule['rule'])) {
            $message = $this->rules->get((string)$rule['rule'])->getDefaultMessage($rule['value'] ?? null);
        }
        $rule['message'] = $message;
        return $rule;
    }

    private function accept(mixed $fieldExtensions): string
    {
        $allowed = $this->config->getAllowedExtensions();
        $wanted = is_array($fieldExtensions) ? $this->extensionList->normalise($fieldExtensions) : [];
        if ($wanted !== []) {
            $allowed = array_values(array_intersect($allowed, $wanted));
        }
        return implode(',', array_map(static fn (string $e): string => '.' . $e, $allowed));
    }
}
