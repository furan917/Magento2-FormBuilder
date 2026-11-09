<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill\Source;

use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class CustomerGender extends AbstractCustomerSource
{
    public function __construct(private readonly CustomerMetadataInterface $customerMetadata)
    {
    }

    public function getCode(): string
    {
        return 'customer.gender';
    }

    public function getLabel(): string
    {
        return (string)__('Customer gender');
    }

    /** @return string|array<string, mixed> */
    protected function fromCustomer(CustomerInterface $customer): string|array
    {
        $id = $customer->getGender();
        if ($id === null || $id === '' || $id === 0) {
            return '';
        }
        try {
            foreach ($this->customerMetadata->getAttributeMetadata('gender')->getOptions() as $option) {
                if ((string)$option->getValue() === (string)$id) {
                    return (string)$option->getLabel();
                }
            }
        } catch (NoSuchEntityException | LocalizedException) {
            return '';
        }
        return '';
    }
}
