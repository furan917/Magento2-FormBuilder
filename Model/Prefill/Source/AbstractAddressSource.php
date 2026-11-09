<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Prefill\Source;

use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\CustomerInterface;

abstract class AbstractAddressSource extends AbstractCustomerSource
{
    protected function billing(CustomerInterface $customer): ?AddressInterface
    {
        return $this->addressById($customer, $customer->getDefaultBilling());
    }

    protected function shipping(CustomerInterface $customer): ?AddressInterface
    {
        return $this->addressById($customer, $customer->getDefaultShipping());
    }

    private function addressById(CustomerInterface $customer, mixed $id): ?AddressInterface
    {
        if ($id === null || $id === '') {
            return null;
        }
        foreach ((array)$customer->getAddresses() as $address) {
            if ((string)$address->getId() === (string)$id) {
                return $address;
            }
        }
        return null;
    }

    /**
     * @return string|array{street: string[], city: string, region_id: string, region: string, postcode: string, country_id: string}
     */
    protected function addressValue(?AddressInterface $address): string|array
    {
        if ($address === null) {
            return '';
        }
        $region = $address->getRegion();
        $regionId = $address->getRegionId() ?: $region?->getRegionId();
        return [
            'street' => array_values(array_map('strval', (array)$address->getStreet())),
            'city' => (string)$address->getCity(),
            'region_id' => $regionId ? (string)$regionId : '',
            'region' => (string)$region?->getRegion(),
            'postcode' => (string)$address->getPostcode(),
            'country_id' => (string)$address->getCountryId(),
        ];
    }
}
