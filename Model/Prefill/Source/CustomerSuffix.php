<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill\Source;

use Magento\Customer\Api\Data\CustomerInterface;

class CustomerSuffix extends AbstractCustomerSource
{

    public function getCode(): string
    {
        return 'customer.suffix';
    }

    public function getLabel(): string
    {
        return (string)__('Customer name suffix');
    }

    /** @return string|array<string, mixed> */
    protected function fromCustomer(CustomerInterface $customer): string|array
    {
        return (string)$customer->getSuffix();
    }
}
