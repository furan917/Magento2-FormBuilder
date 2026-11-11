<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

/**
 * The server stores this answer from the schema, whatever the browser sends.
 *
 * @api
 */
interface FixedValueTypeInterface
{
    /**
     * @param array<string, mixed> $field
     * @return string|null the answer to store, or null when the posted answer stands
     */
    public function fixedValue(array $field): ?string;
}
