<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

/**
 * Optional capability: whether conditions and "matches" rules may reference this type.
 * A type without this interface can be referenced. Notification routing is decided by RoutableTypeInterface.
 *
 * @api
 */
interface ConditionSourceInterface
{
    public function canBeConditionSource(): bool;
}
