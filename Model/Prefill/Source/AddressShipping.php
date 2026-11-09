<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill\Source;

use Magento\Customer\Api\Data\CustomerInterface;

class AddressShipping extends AbstractAddressSource
{
    protected const TYPES = ['address'];

    public function getCode(): string
    {
        return 'address.shipping';
    }

    public function getLabel(): string
    {
        return (string)__('Default shipping address');
    }

    /** @return string|array<string, mixed> */
    protected function fromCustomer(CustomerInterface $customer): string|array
    {
        return $this->addressValue($this->shipping($customer));
    }
}
