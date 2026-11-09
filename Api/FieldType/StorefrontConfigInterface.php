<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

/**
 * Optional capability: adds top-level keys to the storefront form config.
 *
 * Called once per type present in the form. Keys already set (formId, submitUrl, tokenUrl, schema, ... or another
 * type's keys) are never overridden.
 *
 * @api
 */
interface StorefrontConfigInterface
{
    /**
     * @param list<array<string, mixed>> $fields every schema field of this type in the form
     * @param int $storeId current store
     * @return array<string, mixed> config keys to add
     */
    public function storefrontConfig(array $fields, int $storeId): array;
}
