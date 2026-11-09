<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Schema;

/**
 * Sections cannot nest; entries without a `kind` are skipped when reading and kept as they are when rewriting.
 */
class SchemaFields
{
    private const FIELD = ['field'];

    /**
     * @param array<string, mixed> $schema
     * @return list<array<string, mixed>> fields in page order, sections flattened
     */
    public static function all(array $schema): array
    {
        $fields = [];
        foreach (self::children($schema['pages'] ?? []) as $page) {
            foreach (self::children($page['items'] ?? []) as $item) {
                if (($item['kind'] ?? '') === 'field') {
                    $fields[] = $item;
                } elseif (($item['kind'] ?? '') === 'section') {
                    foreach (self::children($item['items'] ?? []) as $child) {
                        if (($child['kind'] ?? '') === 'field') {
                            $fields[] = $child;
                        }
                    }
                }
            }
        }
        return $fields;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, array<string, mixed>> fields that have a name; the last one wins on a repeated name
     */
    public static function byName(array $schema): array
    {
        $fields = [];
        foreach (self::all($schema) as $field) {
            if (isset($field['name'])) {
                $fields[(string)$field['name']] = $field;
            }
        }
        return $fields;
    }

    /**
     * Rewrites every item of the given kinds ('page', 'section', 'field', 'content') in place.
     *
     * @param array<string, mixed> $schema
     * @param callable(array<string, mixed>): array<string, mixed> $rewrite
     * @param list<string> $kinds
     * @return array<string, mixed>
     */
    public static function map(array $schema, callable $rewrite, array $kinds = self::FIELD): array
    {
        return self::rebuild($schema, $rewrite, $kinds);
    }

    /**
     * Drops the fields the callback rejects; pages, sections and content stay.
     *
     * @param array<string, mixed> $schema
     * @param callable(array<string, mixed>): bool $keep
     * @return array<string, mixed>
     */
    public static function filter(array $schema, callable $keep): array
    {
        return self::rebuild($schema, static fn (array $item): ?array => $keep($item) ? $item : null, self::FIELD);
    }

    /**
     * @param array<string, mixed> $schema
     * @param callable(array<string, mixed>): ?array<string, mixed> $visit null drops the item
     * @param list<string> $kinds
     * @return array<string, mixed>
     */
    private static function rebuild(array $schema, callable $visit, array $kinds): array
    {
        if (!is_array($schema['pages'] ?? null)) {
            return $schema;
        }
        $pages = [];
        foreach ($schema['pages'] as $page) {
            if (!is_array($page)) {
                $pages[] = $page;
                continue;
            }
            $page = in_array('page', $kinds, true) ? ($visit($page) ?? $page) : $page;
            if (is_array($page['items'] ?? null)) {
                $page['items'] = self::rebuildItems($page['items'], $visit, $kinds, true);
            }
            $pages[] = $page;
        }
        $schema['pages'] = $pages;
        return $schema;
    }

    /**
     * @param array<int|string, mixed> $items
     * @param callable(array<string, mixed>): ?array<string, mixed> $visit
     * @param list<string> $kinds
     * @return list<mixed>
     */
    private static function rebuildItems(array $items, callable $visit, array $kinds, bool $sectionsAllowed): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['kind'])) {
                $out[] = $item;
                continue;
            }
            if (in_array($item['kind'], $kinds, true)) {
                $item = $visit($item);
                if ($item === null) {
                    continue;
                }
            }
            if ($sectionsAllowed && $item['kind'] === 'section' && is_array($item['items'] ?? null)) {
                $item['items'] = self::rebuildItems($item['items'], $visit, $kinds, false);
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @param mixed $entries
     * @return list<array<string, mixed>> the entries that are arrays
     */
    private static function children(mixed $entries): array
    {
        if (!is_array($entries)) {
            return [];
        }
        return array_values(array_filter($entries, is_array(...)));
    }
}
