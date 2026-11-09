<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

/**
 * @phpstan-type FieldDefinition array<string, mixed>
 * @phpstan-type AnswerValue string|array<int|string, mixed>
 * @phpstan-type SettingSpec array{type: string, default: mixed, label?: string, translatable?: bool, min?: int|float, max?: int|float, values?: string[], value_labels?: array<string, string>, max_length?: int, format?: string, min_items?: int, max_items?: int, multiline?: bool}
 * @api
 */
interface FieldTypeInterface
{
    public function getCode(): string;

    public function getLabel(): string;

    /**
     * One of: input, choice, advanced.
     */
    public function getCategory(): string;

    /**
     * @return string[] validation rule codes this type accepts
     */
    public function getAllowedRules(): array;

    /**
     * String settings may add `max_length` and `format` ('url'; 'mask' = an input mask: a preset code or a custom
     * pattern of 9 a *, which also formats the value server-side); list settings `min_items`, `max_items` and `multiline`.
     * `label` is the already translated builder caption (the humanised key is used without it). `translatable` marks
     * a string or list setting that store views may translate; it is then listed in the form's translation texts.
     * An enum setting may add `value_labels` (value => translated caption) for the builder dropdown; a value without
     * one is shown as itself.
     *
     * @return array<string, SettingSpec>
     */
    public function getSettingsSpec(): array;

    public function hasOptions(): bool;

    public function isFile(): bool;

    public function isMultiValue(): bool;

    public function getTemplate(): string;

    /**
     * Starting values for a new field of this type in the builder: `label` and `draft`.
     *
     * @return array{label?: string, draft?: bool}
     */
    public function getBuilderDefaults(): array;

    /**
     * Message for a failed intrinsic check; null when the type has none.
     *
     * @param FieldDefinition $field
     */
    public function getInvalidMessage(array $field): ?string;

    /**
     * Coerce a posted value; null when absent or of the wrong shape.
     *
     * @param FieldDefinition $field
     * @return AnswerValue|null
     */
    public function normalizeValue(mixed $raw, array $field): string|array|null;

    /**
     * Type-intrinsic check (format, option membership). Returns an error message or null.
     *
     * @param AnswerValue $value
     * @param FieldDefinition $field
     * @param array{values?: array<string, mixed>, store_id?: int} $context
     */
    public function validateValue(string|array $value, array $field, array $context): ?string;
}
