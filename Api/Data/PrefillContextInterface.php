<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Api\Data;

use Magento\Customer\Api\Data\CustomerInterface;

/**
 * What prefill sources read from: the customer (loaded at most once, on first use), the product and the store.
 *
 * @api
 */
interface PrefillContextInterface
{
    public function getStoreId(): int;

    /** @return array<string, mixed>|null */
    public function getProduct(): ?array;

    public function getCustomer(): ?CustomerInterface;
}
