<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill\Source;

use Magento\Customer\Api\Data\CustomerInterface;

class AddressBilling extends AbstractAddressSource
{
    protected const TYPES = ['address'];

    public function getCode(): string
    {
        return 'address.billing';
    }

    public function getLabel(): string
    {
        return (string)__('Default billing address');
    }

    /** @return string|array<string, mixed> */
    protected function fromCustomer(CustomerInterface $customer): string|array
    {
        return $this->addressValue($this->billing($customer));
    }
}
