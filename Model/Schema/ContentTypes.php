<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Schema;

/** The content item types a form can hold, with their admin labels (translated by the reader). */
class ContentTypes
{
    private const LABELS = [
        'heading' => 'Heading',
        'paragraph' => 'Paragraph',
        'divider' => 'Divider',
        'spacer' => 'Spacer',
        'rich_text' => 'Rich text',
        'notice' => 'Notice',
        'summary' => 'Summary',
    ];

    public static function has(string $code): bool
    {
        return isset(self::LABELS[$code]);
    }

    public static function label(string $code): ?string
    {
        return self::LABELS[$code] ?? null;
    }

    /** @return array<string, string> code => untranslated label */
    public static function labels(): array
    {
        return self::LABELS;
    }
}
