<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

/**
 * Optional capability: how the admin submission view and My submissions lay out an answer of this type.
 *
 * Admin view only: emails, CSV and privacy exports keep using DisplayableTypeInterface / the generic fallback.
 * A type without it (or with a null kind) is shown as plain text.
 *
 * @api
 */
interface AdminViewTypeInterface
{
    /** Shown as Yes (any value) or No (empty); always counts as answered. */
    public const KIND_BOOLEAN = 'boolean';
    /** ISO2 codes are shown as country names. */
    public const KIND_COUNTRY = 'country';
    /** Typed text: never swapped for option labels. */
    public const KIND_FREE_TEXT = 'free_text';
    /** Free-text "row: column" lines shown as a definition list. */
    public const KIND_PAIRS = 'pairs';
    /** Values as an ordered list. */
    public const KIND_LIST = 'list';
    /** Values as chips. */
    public const KIND_CHIPS = 'chips';
    /** Stars out of the `max` setting. */
    public const KIND_RATING = 'rating';
    /** Score with the `min` and `max` settings. */
    public const KIND_SCALE = 'scale';
    /** A #rrggbb value with a colour swatch. */
    public const KIND_COLOR = 'color';
    /** Long text keeping its line breaks. */
    public const KIND_MULTILINE = 'multiline';

    /**
     * @param array<string, mixed> $field schema field, or the name/label/type stub of a payload entry whose field was removed
     * @return string|null one of the KIND_* constants, null for the plain layout
     */
    public function adminViewKind(array $field): ?string;
}
