<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

/**
 * Optional capability: the rule library group codes (`postcode`, `phone`, `finance`, `personal`, `store`, `content`)
 * whose formats the type's `preset` rule accepts. Saved rules and library rules without a group code always apply, so
 * an empty list means saved rules only. Without this interface every format applies.
 *
 * @api
 */
interface PresetGroupsInterface
{
    /**
     * @return list<string>
     */
    public function presetGroups(): array;
}
