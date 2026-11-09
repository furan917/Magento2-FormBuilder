<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

/**
 * Optional capability: fields of this type are personal data unless the admin explicitly says otherwise.
 *
 * @api
 */
interface PersonalByDefaultInterface
{
    public function isPersonalByDefault(): bool;
}
