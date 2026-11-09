<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill\Source;

use Magento\Customer\Api\Data\CustomerInterface;

class CustomerDob extends AbstractCustomerSource
{
    protected const TYPES = ['date', 'text', 'hidden'];

    public function getCode(): string
    {
        return 'customer.dob';
    }

    public function getLabel(): string
    {
        return (string)__('Customer date of birth');
    }

    /** @return string|array<string, mixed> */
    protected function fromCustomer(CustomerInterface $customer): string|array
    {
        return preg_match('/^(\d{4}-\d{2}-\d{2})/', (string)$customer->getDob(), $m) === 1 ? $m[1] : '';
    }
}
