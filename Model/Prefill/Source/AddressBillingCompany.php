<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill\Source;

use Magento\Customer\Api\Data\CustomerInterface;

class AddressBillingCompany extends AbstractAddressSource
{
    protected const TYPES = ['text', 'hidden'];

    public function getCode(): string
    {
        return 'address.billing.company';
    }

    public function getLabel(): string
    {
        return (string)__('Billing company');
    }

    /** @return string|array<string, mixed> */
    protected function fromCustomer(CustomerInterface $customer): string|array
    {
        return (string)$this->billing($customer)?->getCompany();
    }
}
