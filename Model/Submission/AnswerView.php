<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface;
use Furan\FormBuilder\Model\FieldType\Pool;
use Magento\Framework\Escaper;

/**
 * Lays stored answers out by the form's pages and sections for the admin view.
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
     * Display strings for an answer: option labels, affixes and country names applied.
     *
     * @param array<string, mixed> $entry
     * @return string[]
     */
    public function values(array $entry): array
    {
        $kind = $this->kind($entry);
        $field = is_array($entry['field'] ?? null) ? $entry['field'] : [];
        $value = $entry['value'] ?? '';
        if ($this->formatter->isDisplayable($this->fieldOf($entry))) {
            $text = $this->formatter->toText($this->fieldOf($entry), $value);
            return $text === '' ? [] : [$text];
        }
        $raw = is_array($value) ? array_values(array_filter($value, is_scalar(...))) : (is_scalar($value) ? [$value] : []);
        $raw = array_values(array_filter(array_map('strval', $raw), static fn (string $v) => $v !== ''));

        if ($kind === AdminViewTypeInterface::KIND_BOOLEAN) {
            return [$raw ? 'Yes' : 'No'];
        }
        if ($kind === AdminViewTypeInterface::KIND_COUNTRY) {
            return array_map($this->formatter->countryName(...), $raw);
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
    public function html(array $entry, Escaper $escaper): ?string
    {
        return $this->formatter->customHtml($this->fieldOf($entry), $entry['value'] ?? '', $escaper);
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
    public function isAnswered(array $entry): bool
    {
        if ($this->kind($entry) === AdminViewTypeInterface::KIND_BOOLEAN) {
            return true;
        }
        $value = $entry['value'] ?? '';
        if ($this->isFile($entry)) {
            return is_array($value) && !empty($value['name']);
        }
        return $this->values($entry) !== [];
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
