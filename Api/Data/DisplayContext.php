<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\Data;

/**
 * Who an answer is displayed to: the store view it belongs to and the reader's locale.
 *
 * @api
 */
class DisplayContext
{
    private function __construct(public readonly int $storeId, public readonly string $locale)
    {
    }

    /** The submission's customer, or an email built as that store view. */
    public static function forStore(int $storeId, string $locale): self
    {
        return new self($storeId, $locale);
    }

    public static function forAdmin(string $locale): self
    {
        return new self(0, $locale);
    }
}
