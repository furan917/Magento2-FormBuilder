<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

/**
 * Optional capability: answers of this type can be compared with a date relative to today
 * (a condition rule's `value_date`). A type without it cannot be used for relative date rules.
 *
 * @api
 */
interface RelativeDateSourceInterface
{
    /**
     * PHP date format of the resolved date: 'Y-m-d' or 'Y-m'.
     */
    public function relativeDateFormat(): string;
}
