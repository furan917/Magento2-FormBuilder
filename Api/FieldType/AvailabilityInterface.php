<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

/**
 * Optional capability: a type that depends on something optional (another module, a setting). It stays registered so
 * stored forms load, but while unavailable it is hidden from the builder palette, refused on new fields, not rendered
 * and not validated. A stored field of the type is kept untouched on save.
 *
 * @api
 */
interface AvailabilityInterface
{
    /** Null = module level (palette, normaliser); a store id also applies that store view's own switches. */
    public function isAvailable(?int $storeId = null): bool;
}
