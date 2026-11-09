<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill\Source;

use Magento\Customer\Api\Data\CustomerInterface;

class CustomerEmail extends AbstractCustomerSource
{
    protected const TYPES = ['email', 'text', 'hidden'];

    public function getCode(): string
    {
        return 'customer.email';
    }

    public function getLabel(): string
    {
        return (string)__('Customer email');
    }

    /** @return string|array<string, mixed> */
    protected function fromCustomer(CustomerInterface $customer): string|array
    {
        return (string)$customer->getEmail();
    }
}
