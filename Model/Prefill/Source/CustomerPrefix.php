<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill\Source;

use Magento\Customer\Api\Data\CustomerInterface;

class CustomerPrefix extends AbstractCustomerSource
{

    public function getCode(): string
    {
        return 'customer.prefix';
    }

    public function getLabel(): string
    {
        return (string)__('Customer name prefix');
    }

    /** @return string|array<string, mixed> */
    protected function fromCustomer(CustomerInterface $customer): string|array
    {
        return (string)$customer->getPrefix();
    }
}
