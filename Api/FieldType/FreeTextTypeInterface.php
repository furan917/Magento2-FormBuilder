<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

/**
 * Optional capability: analytics lists the latest answers of this type as free text.
 *
 * @api
 */
interface FreeTextTypeInterface
{
    public function isFreeText(): bool;
}
