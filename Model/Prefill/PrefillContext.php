<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill;

use Furan\FormBuilder\Api\Data\PrefillContextInterface;
use Magento\Customer\Api\Data\CustomerInterface;

/** Built with `new`: the customer loader is a closure, which DI cannot supply. */
class PrefillContext implements PrefillContextInterface
{
    private bool $customerLoaded = false;
    private ?CustomerInterface $customer = null;

    /**
     * @param \Closure(): ?CustomerInterface $customerLoader
     * @param array<string, mixed>|null $product see PrefillService::product()
     */
    public function __construct(
        private readonly int $storeId,
        private readonly ?array $product,
        private readonly \Closure $customerLoader
    ) {
    }

    public function getStoreId(): int
    {
        return $this->storeId;
    }

    /** @return array<string, mixed>|null */
    public function getProduct(): ?array
    {
        return $this->product;
    }

    public function getCustomer(): ?CustomerInterface
    {
        if (!$this->customerLoaded) {
            $this->customerLoaded = true;
            $this->customer = ($this->customerLoader)();
        }
        return $this->customer;
    }
}
