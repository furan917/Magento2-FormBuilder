<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation\Rule;

use Furan\FormBuilder\Model\Validation\Patterns;

abstract class AbstractNumericRule extends AbstractRule
{
    /**
     * @param string|array<int|string, mixed> $value
     */
    protected function isNumeric(string|array $value): bool
    {
        return is_string($value) && preg_match(Patterns::NUMERIC, $value) === 1;
    }
}
