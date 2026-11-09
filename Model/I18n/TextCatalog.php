<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\I18n;

use Furan\FormBuilder\Model\FieldType\Pool;

/**
 * Lists every visitor-facing text of a form that a store view may translate, keyed by stable references
 * (item id, option value, rule key, setting key) so translations survive reordering and relabelling elsewhere.
 * Mirrored in view/adminhtml/web/js/builder-i18n.js; Test/fixtures/translatable-texts.json keeps both in step.
 *
 * @phpstan-import-type FormSchema from \Furan\FormBuilder\Api\Data\FormInterface
 * @phpstan-import-type FormSettings from \Furan\FormBuilder\Api\Data\FormInterface
 * @phpstan-type TextEntry array{path: string[], text: string, max: int, multiline: bool, url: bool}
 */
class TextCatalog
{
    /** Form settings shown to visitors or sent to them; notification settings stay in the admin's language. */
    public const FORM_TEXTS = [
        'submit_label' => [50, false],
        'next_label' => [50, false],
        'prev_label' => [50, false],
        'success_message' => [2000, true],
        'page_title' => [255, false],
        'meta_description' => [500, false],
        'autoresponder_subject' => [255, false],
        'autoresponder_body' => [5000, true],
        'closed_message' => [500, false],
        'not_open_message' => [500, false],
    ];
    private const CONTENT_TEXT_LIMITS = ['heading' => [255, false], 'notice' => [1000, true]];

    public function __construct(private readonly Pool $fieldTypes)
    {
    }

    /**
     * @param FormSchema $schema
     * @param FormSettings $settings
     * @return list<TextEntry>
     */
    public function collect(array $schema, array $settings): array
    {
        $entries = [];
        foreach (self::FORM_TEXTS as $key => [$max, $multiline]) {
            $this->add($entries, ['form', $key], $settings[$key] ?? '', $max, $multiline);
        }
        foreach ($schema['pages'] ?? [] as $page) {
            $this->add($entries, ['items', (string)($page['id'] ?? ''), 'title'], $page['title'] ?? '', 255);
            $this->collectItems($entries, $page['items'] ?? []);
        }
        return $entries;
    }

    /**
     * @param list<TextEntry> $entries
     * @param array<int|string, mixed> $items
     */
    private function collectItems(array &$entries, array $items): void
    {
        foreach ($items as $item) {
            $id = (string)($item['id'] ?? '');
            match ($item['kind'] ?? '') {
                'section' => $this->collectSection($entries, $id, $item),
                'content' => $this->collectContent($entries, $id, $item),
                'field' => $this->collectField($entries, $id, $item),
                default => null,
            };
        }
    }

    /**
     * @param list<TextEntry> $entries
     * @param array<string, mixed> $section
     */
    private function collectSection(array &$entries, string $id, array $section): void
    {
        $this->add($entries, ['items', $id, 'title'], $section['title'] ?? '', 255);
        $this->add($entries, ['items', $id, 'description'], $section['description'] ?? '', 1000, true);
        $this->collectItems($entries, $section['items'] ?? []);
    }

    /**
     * @param list<TextEntry> $entries
     * @param array<string, mixed> $content
     */
    private function collectContent(array &$entries, string $id, array $content): void
    {
        [$max, $multiline] = self::CONTENT_TEXT_LIMITS[$content['type'] ?? ''] ?? [5000, true];
        $this->add($entries, ['items', $id, 'title'], $content['title'] ?? '', 255);
        $this->add($entries, ['items', $id, 'text'], $content['text'] ?? '', $max, $multiline);
    }

    /**
     * @param list<TextEntry> $entries
     * @param array<string, mixed> $field
     */
    private function collectField(array &$entries, string $id, array $field): void
    {
        $this->add($entries, ['items', $id, 'label'], $field['label'] ?? '', 255);
        $this->add($entries, ['items', $id, 'help'], $field['help'] ?? '', 1000, true);
        $this->add($entries, ['items', $id, 'placeholder'], $field['placeholder'] ?? '', 255);
        foreach ($field['options'] ?? [] as $option) {
            $value = (string)($option['value'] ?? '');
            $this->add($entries, ['items', $id, 'options', $value, 'label'], $option['label'] ?? '', 255);
            $this->add($entries, ['items', $id, 'options', $value, 'description'], $option['description'] ?? '', 255);
        }
        foreach ($field['rules'] ?? [] as $rule) {
            $this->add($entries, ['items', $id, 'rules', self::ruleKey($rule), 'message'], $rule['message'] ?? '', 255);
        }
        $type = (string)($field['type'] ?? '');
        $spec = $this->fieldTypes->has($type) ? $this->fieldTypes->get($type)->getSettingsSpec() : [];
        foreach ($spec as $key => $setting) {
            if (empty($setting['translatable'])) {
                continue;
            }
            $value = $field['settings'][$key] ?? '';
            if (($setting['type'] ?? '') === 'list') {
                foreach (is_array($value) ? array_values($value) : [] as $index => $entry) {
                    $this->add($entries, ['items', $id, 'settings', $key, (string)$index], $entry, 255);
                }
                continue;
            }
            $this->add(
                $entries,
                ['items', $id, 'settings', $key],
                $value,
                (int)($setting['max_length'] ?? 255),
                !empty($setting['multiline']),
                ($setting['format'] ?? '') === 'url'
            );
        }
    }

    /**
     * Same key the schema normaliser de-duplicates rules by.
     *
     * @param array<string, mixed> $rule
     */
    public static function ruleKey(array $rule): string
    {
        $code = (string)($rule['rule'] ?? '');
        return $code === 'preset' ? $code . ':' . (string)($rule['value'] ?? '') : $code;
    }

    /**
     * @param list<TextEntry> $entries
     * @param string[] $path
     */
    private function add(
        array &$entries,
        array $path,
        mixed $text,
        int $max,
        bool $multiline = false,
        bool $url = false
    ): void {
        if (is_string($text) && trim($text) !== '') {
            $entries[] = ['path' => $path, 'text' => $text, 'max' => $max, 'multiline' => $multiline, 'url' => $url];
        }
    }
}
