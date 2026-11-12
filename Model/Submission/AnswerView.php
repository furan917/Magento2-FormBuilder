<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Api\Data\DisplayContext;
use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface;
use Furan\FormBuilder\Api\FieldType\FixedValueTypeInterface;
use Furan\FormBuilder\Model\FieldType\Pool;
use Magento\Framework\Escaper;

/**
 * Lays stored answers out by the form's pages and sections for the admin view, My submissions and emails.
 */
class AnswerView
{
    public function __construct(private readonly ValueFormatter $formatter, private readonly Pool $fieldTypes)
    {
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function isFile(array $entry): bool
    {
        return $this->fieldTypes->isFile((string)($entry['type'] ?? ''));
    }

    /**
     * The stored answer or the current field is server-owned (a field since retyped keeps its old answer's type).
     *
     * @param array<string, mixed> $entry
     */
    public function isServerValue(array $entry): bool
    {
        return $this->fieldTypes->isServerValue((string)($entry['type'] ?? ''))
            || $this->fieldTypes->isServerValue((string)($entry['field']['type'] ?? ''));
    }

    /**
     * Whether a server-owned answer came from the visitor's browser; false for a removed or retyped field.
     *
     * @param array<string, mixed> $entry
     */
    public function isBrowserValue(array $entry): bool
    {
        $field = is_array($entry['field'] ?? null) ? $entry['field'] : [];
        $code = (string)($entry['type'] ?? '');
        if ($field === [] || (string)($field['type'] ?? '') !== $code || !$this->fieldTypes->isServerValue($code)
            || !$this->fieldTypes->has($code) || !$this->fieldTypes->get($code) instanceof FixedValueTypeInterface
        ) {
            return false;
        }
        return ($field['prefill'] ?? '') === '' && $this->fieldTypes->fixedValue($field) === null;
    }

    /**
     * @param array<string, mixed> $schema Current form schema; answers whose field was since removed go in a trailing group.
     * @param array<int, mixed> $payload
     * @return list<array{title: string, removed: bool, sections: list<array{title: string, entries: list<array<string, mixed>>}>}>
     */
    public function group(array $schema, array $payload): array
    {
        $byName = [];
        foreach ($payload as $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $byName[(string)$entry['name']] = $entry;
            }
        }

        $groups = [];
        foreach ((array)($schema['pages'] ?? []) as $i => $page) {
            if (!is_array($page)) {
                continue;
            }
            $sections = [];
            $loose = [];
            foreach ((array)($page['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (($item['kind'] ?? '') === 'section') {
                    // Keep section order relative to the page's loose fields.
                    if ($loose) {
                        $sections[] = ['title' => '', 'entries' => $loose];
                        $loose = [];
                    }
                    $entries = $this->take((array)($item['items'] ?? []), $byName);
                    if ($entries) {
                        $sections[] = ['title' => (string)($item['title'] ?? ''), 'entries' => $entries];
                    }
                    continue;
                }
                array_push($loose, ...$this->take([$item], $byName));
            }
            if ($loose) {
                $sections[] = ['title' => '', 'entries' => $loose];
            }
            if ($sections) {
                $groups[] = ['title' => (string)($page['title'] ?? '') ?: 'Page ' . ($i + 1), 'removed' => false, 'sections' => $sections];
            }
        }

        if ($byName) {
            $groups[] = [
                'title' => '',
                'removed' => true,
                'sections' => [['title' => '', 'entries' => array_map(
                    static fn (array $entry) => $entry + ['field' => null],
                    array_values($byName)
                )]],
            ];
        }
        return $groups;
    }

    /**
     * Display strings for an answer in the reader's locale: option labels, affixes, dates, country names and a
     * translated Yes or No applied.
     *
     * @param array<string, mixed> $entry
     * @return string[]
     */
    public function values(array $entry, DisplayContext $context): array
    {
        $kind = $this->kind($entry);
        $field = is_array($entry['field'] ?? null) ? $entry['field'] : [];
        $value = $entry['value'] ?? '';
        $local = $this->formatter->localText($this->fieldOf($entry), $value, $context);
        if ($local !== null) {
            return [$local];
        }
        if ($this->formatter->isDisplayable($this->fieldOf($entry))) {
            $text = $this->formatter->toText($this->fieldOf($entry), $value, $context);
            return $text === '' ? [] : [$text];
        }
        $raw = $this->raw($value);

        if ($kind === AdminViewTypeInterface::KIND_BOOLEAN) {
            return [(string)($raw !== [] ? __('Yes') : __('No'))];
        }

        $labels = $this->formatter->optionLabels($this->fieldOf($entry));
        $prefix = (string)($field['settings']['prefix'] ?? '');
        $suffix = (string)($field['settings']['suffix'] ?? '');

        return array_map(
            static fn (string $v) => $labels[$v] ?? $prefix . $v . ($suffix !== '' ? ' ' . $suffix : ''),
            $raw
        );
    }

    /**
     * The field type's own escaped HTML for the answer, or null when the type has none.
     *
     * @param array<string, mixed> $entry
     */
    public function html(array $entry, Escaper $escaper, DisplayContext $context): ?string
    {
        return $this->formatter->customHtml($this->fieldOf($entry), $entry['value'] ?? '', $escaper, $context);
    }

    /**
     * The layout kind the answer's field type asks for; null for plain text, unknown or removed types.
     *
     * @param array<string, mixed> $entry
     */
    public function kind(array $entry): ?string
    {
        $code = (string)($entry['type'] ?? '');
        $type = $this->fieldTypes->has($code) ? $this->fieldTypes->get($code) : null;
        return $type instanceof AdminViewTypeInterface ? $type->adminViewKind($this->fieldOf($entry)) : null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function isAnswered(array $entry, DisplayContext $context): bool
    {
        if ($this->kind($entry) === AdminViewTypeInterface::KIND_BOOLEAN) {
            return $this->raw($entry['value'] ?? '') !== [];
        }
        $value = $entry['value'] ?? '';
        if ($this->isFile($entry)) {
            return is_array($value) && !empty($value['name']);
        }
        return $this->values($entry, $context) !== [];
    }

    /**
     * The non-empty scalar parts of a stored answer.
     *
     * @return list<string>
     */
    private function raw(mixed $value): array
    {
        $raw = is_array($value) ? array_values(array_filter($value, is_scalar(...))) : (is_scalar($value) ? [$value] : []);
        return array_values(array_filter(array_map('strval', $raw), static fn (string $v) => $v !== ''));
    }

    /**
     * The schema field attached by group(), else the name/label/type stub from the payload entry.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function fieldOf(array $entry): array
    {
        $field = $entry['field'] ?? null;
        $stub = ['name' => $entry['name'] ?? '', 'label' => $entry['label'] ?? '', 'type' => $entry['type'] ?? ''];
        return is_array($field) && $field !== [] ? $field + $stub : $stub;
    }

    /**
     * @param array<int, mixed> $items
     * @param array<string, array<string, mixed>> $byName
     * @return list<array<string, mixed>>
     */
    private function take(array $items, array &$byName): array
    {
        $out = [];
        foreach ($items as $item) {
            $name = is_array($item) && ($item['kind'] ?? '') === 'field' ? (string)($item['name'] ?? '') : '';
            if ($name !== '' && isset($byName[$name])) {
                $out[] = $byName[$name] + ['field' => $item];
                unset($byName[$name]);
            }
        }
        return $out;
    }
}
