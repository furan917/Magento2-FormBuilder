<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

/**
 * Optional capability: whether notification routes may match on this type. A type without this interface can
 * be routed unless it is a file type.
 *
 * @api
 */
interface RoutableTypeInterface
{
    public function canBeRouted(): bool;
}
