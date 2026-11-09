<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\FieldType;

/**
 * Optional capability: translated messages the browser needs for this type, added to the public schema.
 * The returned keys (messages, rows_message, type_message) are read by ffb-core and must not be renamed.
 *
 * @api
 */
interface ClientMessagesInterface
{
    /**
     * @param array<string, mixed> $field schema field
     * @return array<string, mixed> keys merged into the public field
     */
    public function getClientMessages(array $field): array;
}
