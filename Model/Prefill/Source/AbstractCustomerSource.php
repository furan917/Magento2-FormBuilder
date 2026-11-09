<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill\Source;

use Furan\FormBuilder\Api\Data\PrefillContextInterface;
use Furan\FormBuilder\Model\Prefill\Sources;
use Magento\Customer\Api\Data\CustomerInterface;

abstract class AbstractCustomerSource extends AbstractSource
{
    public function getGroup(): string
    {
        return Sources::GROUP_CUSTOMER;
    }

    public function resolve(PrefillContextInterface $context): string|array|null
    {
        $customer = $context->getCustomer();
        return $customer === null ? '' : $this->fromCustomer($customer);
    }

    /** @return string|array<string, mixed> */
    abstract protected function fromCustomer(CustomerInterface $customer): string|array;
}
