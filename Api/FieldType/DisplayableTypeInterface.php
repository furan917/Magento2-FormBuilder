<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

use Magento\Framework\Escaper;

/**
 * Optional capability: the field type renders its stored answer as text and as HTML.
 *
 * $field is the current schema field by name; when the field was removed from the form it is
 * ['name', 'label', 'type'] taken from the stored payload entry.
 *
 * @api
 */
interface DisplayableTypeInterface
{
    /**
     * @param array<string, mixed> $field schema field or payload stub
     * @param mixed $value stored answer
     */
    public function toText(array $field, mixed $value): string;

    /**
     * The result is printed unescaped, so the implementation must escape every dynamic part.
     *
     * @param array<string, mixed> $field schema field or payload stub
     * @param mixed $value stored answer
     */
    public function toHtml(array $field, mixed $value, Escaper $escaper): string;
}
