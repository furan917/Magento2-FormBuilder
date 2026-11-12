<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

/**
 * Optional capability: the longest answer the type keeps. The type's normalizeValue() cuts longer text
 * (AbstractFieldType does); the form save refuses a longer Default value or max_length rule.
 *
 * @api
 */
interface MaxLengthInterface
{
    public function maxLength(): int;
}
