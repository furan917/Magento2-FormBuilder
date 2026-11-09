<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\I18n;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Model\Schema\SchemaFields;
use Furan\FormBuilder\Model\Url\RedirectValidator;

/**
 * Store-view overlays on a single form. A translation is {"t": text, "s": the default text it was written for};
 * a missing or empty one falls back to the default, and a changed default marks it for review instead of
 * silently showing stale wording.
 *
 * @phpstan-import-type FormSchema from \Furan\FormBuilder\Api\Data\FormInterface
 * @phpstan-import-type FormSettings from \Furan\FormBuilder\Api\Data\FormInterface
 * @phpstan-import-type TranslationOverlay from \Furan\FormBuilder\Api\Data\FormInterface
 */
class Translator
{
    /** Stock texts every new form starts with; left as-is they follow the store view's language pack. */
    private const STOCK_FORM_TEXTS = [
        'submit_label' => 'Submit',
        'next_label' => 'Next',
        'prev_label' => 'Back',
        'success_message' => 'Thank you, your form has been sent.',
        'closed_message' => 'This form is closed.',
        'not_open_message' => 'This form is not open yet.',
    ];
    private const STOCK_SUMMARY_TITLE = 'Review your answers';

    public function __construct(
        private readonly TextCatalog $catalog,
        private readonly RedirectValidator $redirectValidator
    ) {
    }

    /** A copy of $form with its texts in $storeId's language; never save the copy. */
    public function localize(FormInterface $form, int $storeId): FormInterface
    {
        $overlay = $form->getTranslations()[$storeId] ?? [];
        [$schema, $settings] = $overlay === []
            ? [$form->getSchema(), $form->getSettings()]
            : $this->apply($form->getSchema(), $form->getSettings(), $overlay);
        [$schema, $settings] = $this->translateStockTexts($schema, $settings, $overlay);
        if ($schema === $form->getSchema() && $settings === $form->getSettings()) {
            return $form;
        }
        $copy = clone $form;
        $copy->setSchema($schema)->setSettings($settings);
        return $copy;
    }

    /**
     * @param FormSchema $schema
     * @param FormSettings $settings
     * @param TranslationOverlay $overlay
     * @return array{0: FormSchema, 1: FormSettings}
     */
    private function translateStockTexts(array $schema, array $settings, array $overlay): array
    {
        foreach (self::STOCK_FORM_TEXTS as $key => $stock) {
            if (($settings[$key] ?? null) === $stock && $this->lookup($overlay, ['form', $key]) === null) {
                $settings[$key] = (string)__($stock);
            }
        }
        $schema = SchemaFields::map($schema, function (array $item) use ($overlay): array {
            if (($item['type'] ?? '') === 'summary' && ($item['title'] ?? '') === self::STOCK_SUMMARY_TITLE
                && $this->lookup($overlay, ['items', (string)($item['id'] ?? ''), 'title']) === null
            ) {
                $item['title'] = (string)__(self::STOCK_SUMMARY_TITLE);
            }
            return $item;
        }, ['content']);
        return [$schema, $settings];
    }

    /**
     * @param FormSchema $schema
     * @param FormSettings $settings
     * @param TranslationOverlay $overlay
     * @return array{0: FormSchema, 1: FormSettings}
     */
    public function apply(array $schema, array $settings, array $overlay): array
    {
        $texts = [];
        foreach ($this->catalog->collect($schema, $settings) as $entry) {
            $text = $this->lookup($overlay, $entry['path']);
            if ($text !== null) {
                $texts[implode("\0", $entry['path'])] = $text;
            }
        }
        if ($texts === []) {
            return [$schema, $settings];
        }
        foreach (TextCatalog::FORM_TEXTS as $key => $limits) {
            $settings[$key] = $texts["form\0" . $key] ?? ($settings[$key] ?? '');
        }
        $schema = SchemaFields::map(
            $schema,
            fn (array $item): array => $this->applyItem($item, $texts),
            ['page', 'section', 'field', 'content']
        );
        return [$schema, $settings];
    }

    /**
     * Keeps only translations of texts the form still has, cleaned like the default text, for known stores.
     *
     * @param array<int|string, mixed> $raw store id => overlay
     * @param FormSchema $schema
     * @param FormSettings $settings
     * @param int[] $storeIds
     * @return array<int, TranslationOverlay>
     */
    public function normalize(mixed $raw, array $schema, array $settings, array $storeIds): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $entries = $this->catalog->collect($schema, $settings);
        $clean = [];
        foreach ($raw as $storeId => $overlay) {
            $storeId = (int)$storeId;
            if (!in_array($storeId, $storeIds, true) || !is_array($overlay)) {
                continue;
            }
            foreach ($entries as $entry) {
                $value = $this->read($overlay, $entry['path']);
                $text = $this->clean($value['t'] ?? null, $entry['max'], $entry['multiline']);
                if ($text === '' || ($entry['url'] && !$this->redirectValidator->isAllowed($text))) {
                    continue;
                }
                $source = $this->clean($value['s'] ?? null, $entry['max'], $entry['multiline']);
                $clean[$storeId] ??= [];
                $this->write($clean[$storeId], $entry['path'], ['t' => $text, 's' => $source !== '' ? $source : $entry['text']]);
            }
        }
        return array_filter($clean);
    }

    /**
     * @param FormSchema $schema
     * @param FormSettings $settings
     * @param TranslationOverlay $overlay
     * @return array{total: int, translated: int, outdated: int}
     */
    public function progress(array $schema, array $settings, array $overlay): array
    {
        $stats = ['total' => 0, 'translated' => 0, 'outdated' => 0];
        foreach ($this->catalog->collect($schema, $settings) as $entry) {
            $stats['total']++;
            $value = $this->read($overlay, $entry['path']);
            if (is_array($value) && ($value['t'] ?? '') !== '') {
                $stats['translated']++;
                if (($value['s'] ?? '') !== $entry['text']) {
                    $stats['outdated']++;
                }
            }
        }
        return $stats;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, string> $texts
     * @return array<string, mixed>
     */
    private function applyItem(array $item, array $texts): array
    {
        $id = "items\0" . ($item['id'] ?? '') . "\0";
        foreach (['label', 'help', 'placeholder', 'title', 'description', 'text'] as $key) {
            if (isset($texts[$id . $key])) {
                $item[$key] = $texts[$id . $key];
            }
        }
        foreach ($item['options'] ?? [] as $index => $option) {
            foreach (['label', 'description'] as $key) {
                $text = $texts[$id . "options\0" . ($option['value'] ?? '') . "\0" . $key] ?? null;
                if ($text !== null) {
                    $item['options'][$index][$key] = $text;
                }
            }
        }
        foreach ($item['rules'] ?? [] as $index => $rule) {
            $text = $texts[$id . "rules\0" . TextCatalog::ruleKey($rule) . "\0message"] ?? null;
            if ($text !== null) {
                $item['rules'][$index]['message'] = $text;
            }
        }
        foreach (is_array($item['settings'] ?? null) ? $item['settings'] : [] as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $i => $entry) {
                    $item['settings'][$key][$i] = $texts[$id . "settings\0" . $key . "\0" . $i] ?? $entry;
                }
            } elseif (isset($texts[$id . "settings\0" . $key])) {
                $item['settings'][$key] = $texts[$id . "settings\0" . $key];
            }
        }
        return $item;
    }

    /**
     * @param TranslationOverlay $overlay
     * @param string[] $path
     */
    private function lookup(array $overlay, array $path): ?string
    {
        $value = $this->read($overlay, $path);
        $text = is_array($value) ? ($value['t'] ?? null) : null;
        return is_string($text) && $text !== '' ? $text : null;
    }

    /**
     * @param TranslationOverlay $overlay
     * @param string[] $path
     */
    private function read(array $overlay, array $path): mixed
    {
        $node = $overlay;
        foreach ($path as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[$key];
        }
        return $node;
    }

    /**
     * @param array<string, mixed> $target
     * @param string[] $path
     * @param array<string, string> $value
     */
    private function write(array &$target, array $path, array $value): void
    {
        $node = &$target;
        foreach ($path as $key) {
            if (!isset($node[$key]) || !is_array($node[$key])) {
                $node[$key] = [];
            }
            $node = &$node[$key];
        }
        $node = $value;
    }

    /** Same cleaning the schema normaliser applies to the default text. */
    private function clean(mixed $value, int $max, bool $multiline): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $string = strip_tags((string)$value);
        if ($multiline) {
            $string = str_replace(["\r\n", "\r"], "\n", $string);
        }
        $string = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $string) ?? '';
        if (!$multiline) {
            $string = str_replace("\n", ' ', $string);
        }
        return mb_substr(trim($string), 0, $max);
    }
}
