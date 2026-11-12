<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\FreeTextTypeInterface;
use Furan\FormBuilder\Api\FieldTypeInterface;

/**
 * Options of a free-text type are suggestions. Mirrored by ffb-core (Test/fixtures/suggest-match.json).
 */
class SuggestionMatcher
{
    // phpcs:ignore Magento2.Functions.StaticFunction -- a pure capability test shared by Pool and ConditionNormalizer
    public static function appliesTo(FieldTypeInterface $type): bool
    {
        return $type->hasOptions() && $type instanceof FreeTextTypeInterface && $type->isFreeText();
    }

    /**
     * Text equal (trimmed, any case) to an option's label, first in option order, else to an option's value, gives
     * that option's value; other text is returned trimmed.
     *
     * @param array<mixed> $options
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- a pure helper for any free-text option type
    public static function match(string $text, array $options): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $wanted = mb_strtolower($text);
        $options = array_filter($options, is_array(...));
        foreach (['label', 'value'] as $key) {
            foreach ($options as $option) {
                if (mb_strtolower(trim((string)($option[$key] ?? ''))) === $wanted) {
                    return (string)($option['value'] ?? '');
                }
            }
        }
        return $text;
    }
}
