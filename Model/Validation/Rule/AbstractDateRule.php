<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation\Rule;

use Furan\FormBuilder\Model\Validation\Patterns;

abstract class AbstractDateRule extends AbstractRule
{
    /**
     * Works for date and datetime values; null when the value does not start with a date.
     *
     * @param string|array<int|string, mixed> $value
     */
    protected function datePart(string|array $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $part = substr($value, 0, 10);
        return preg_match(Patterns::DATE, $part) === 1 ? $part : null;
    }
}
