<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

/**
 * The field's Default value: how the builder asks for it and which values the server accepts at save.
 * A type without this interface takes free text checked by validateValue().
 *
 * @api
 */
interface DefaultValueInterface
{
    public const INPUT_TEXT = 'text';
    public const INPUT_OPTION = 'option';

    /**
     * @return string|null null = no Default (hidden in the builder, cleared on save); else the builder input:
     *     text, option, number, date, datetime-local, month, time or color
     */
    public function defaultInput(): ?string;

    /**
     * @return array<string, string>|null value => label in the builder's order, when the option picker lists values
     *     the field does not store as options (e.g. countries); null = the field's own options
     */
    public function defaultChoices(): ?array;

    /**
     * @param string $default normalised by the type's normalizeValue()
     * @param array<string, mixed> $field the normalised field
     */
    public function isValidDefault(string $default, array $field): bool;
}
