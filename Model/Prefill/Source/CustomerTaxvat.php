<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill\Source;

use Magento\Customer\Api\Data\CustomerInterface;

class CustomerTaxvat extends AbstractCustomerSource
{

    public function getCode(): string
    {
        return 'customer.taxvat';
    }

    public function getLabel(): string
    {
        return (string)__('Customer tax/VAT number');
    }

    /** @return string|array<string, mixed> */
    protected function fromCustomer(CustomerInterface $customer): string|array
    {
        return (string)$customer->getTaxvat();
    }
}
