<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

/**
 * Optional capability: turns a stored answer value into the label analytics charts show for it.
 *
 * Applied to choice charts when the option is not labelled by the form's own options (for example ISO country
 * codes shown as names). Admin only: the admin locale applies. Return the value itself when there is no better label.
 *
 * @api
 */
interface StatsLabelInterface
{
    /**
     * @param string $value stored answer value
     * @param array<string, mixed> $field the current schema field
     */
    public function statsLabel(string $value, array $field): string;
}
