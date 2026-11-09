<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill\Source;

use Magento\Customer\Api\Data\CustomerInterface;

class CustomerLastname extends AbstractCustomerSource
{

    public function getCode(): string
    {
        return 'customer.lastname';
    }

    public function getLabel(): string
    {
        return (string)__('Customer last name');
    }

    /** @return string|array<string, mixed> */
    protected function fromCustomer(CustomerInterface $customer): string|array
    {
        return (string)$customer->getLastname();
    }
}
