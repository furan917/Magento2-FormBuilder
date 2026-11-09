<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill\Source;

use Magento\Customer\Api\Data\CustomerInterface;

class AddressBillingTelephone extends AbstractAddressSource
{
    protected const TYPES = ['tel', 'text', 'hidden'];

    public function getCode(): string
    {
        return 'address.billing.telephone';
    }

    public function getLabel(): string
    {
        return (string)__('Billing telephone');
    }

    /** @return string|array<string, mixed> */
    protected function fromCustomer(CustomerInterface $customer): string|array
    {
        return (string)(
            $this->billing($customer)?->getTelephone() ?: $this->shipping($customer)?->getTelephone()
        );
    }
}
