<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation;

use Furan\FormBuilder\Api\FieldType\PresetGroupsInterface;
use Furan\FormBuilder\Api\FieldTypeInterface;

/** Whether a rule library format may be used by a field type's `preset` rule. */
class PresetFit
{
    /** An entry without a group code (saved rules) fits every type. */
    public function fitsType(string $groupCode, FieldTypeInterface $type): bool
    {
        return $groupCode === ''
            || !$type instanceof PresetGroupsInterface
            || in_array($groupCode, $type->presetGroups(), true);
    }
}
