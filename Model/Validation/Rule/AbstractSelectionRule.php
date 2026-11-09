<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation\Rule;

abstract class AbstractSelectionRule extends AbstractRule
{
    /**
     * @param string|array<int|string, mixed> $value
     */
    protected function selectedCount(string|array $value): int
    {
        return is_array($value) ? count($value) : ($value === '' ? 0 : 1);
    }
}
