<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;

/** @api */
interface FormManagementInterface
{
    /**
     * $definition keys: identifier, title, is_active, store_ids, schema, settings, translations (absent keeps saved).
     * $baseline's schema and settings count as stored (default $existing); pass the source for a copy.
     *
     * @param array<string, mixed> $definition
     * @throws InvalidSchemaException with every message
     */
    public function save(
        ?FormInterface $existing,
        array $definition,
        FormWriteContext $context,
        ?FormInterface $baseline = null
    ): FormInterface;
}
