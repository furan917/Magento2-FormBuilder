<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

/**
 * Optional capability: the field type chooses its analytics chart kind. The kind is frozen per field once stored,
 * so only new series pick up a changed kind. Types without this interface chart as KIND_ANSWERED_ONLY.
 *
 * The stored value must suit the kind: KIND_CHOICE counts scalar (string) values only, KIND_MULTI a list of strings,
 * KIND_NUMBER/KIND_DISCRETE numeric strings, KIND_DATE/KIND_DATE_RANGE date strings, KIND_MATRIX and KIND_ADDRESS
 * the shapes of the built-in matrix and address types.
 *
 * @api
 */
interface StatsAwareTypeInterface
{
    public const KIND_NONE = 0;
    public const KIND_CHOICE = 1;
    public const KIND_MULTI = 2;
    public const KIND_DISCRETE = 3;
    public const KIND_NUMBER = 4;
    public const KIND_DATE = 5;
    public const KIND_DATE_RANGE = 6;
    public const KIND_MATRIX = 7;
    public const KIND_ADDRESS = 8;
    public const KIND_ANSWERED_ONLY = 9;

    /**
     * @param array<string, mixed> $field schema field
     * @return int|null one of the KIND_* constants; null or a value outside the set = no chart (KIND_NONE)
     */
    public function statsKind(array $field): ?int;
}
