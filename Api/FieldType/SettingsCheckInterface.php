<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

/**
 * Optional capability: checks that the field's normalised settings fit together, when the form is saved.
 *
 * @api
 */
interface SettingsCheckInterface
{
    /**
     * @param array<string, mixed> $field the normalised field: settings, rules and options
     * @return list<string> translated problems, each a sentence fragment such as "the minimum is greater than the maximum"
     */
    public function settingsErrors(array $field): array;
}
