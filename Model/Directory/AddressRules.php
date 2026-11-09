<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Directory;

use Magento\Directory\Model\AllowedCountries;
use Magento\Directory\Model\Country\Postcode\ConfigInterface;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * @phpstan-type DirectoryData array{allowedCountries: string[], regionsRequired: string[], optionalZip: string[],
 *     postcodes: array<string, string[]>, streetLines: int, regions: array<string, array<string, string>>}
 */
class AddressRules
{
    private const MAX_STREET_LINES = 4;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AllowedCountries $allowedCountries,
        private readonly ConfigInterface $postcodeConfig,
        private readonly CollectionFactory $regionCollectionFactory
    ) {
    }

    /**
     * Reads with an explicit store id; the Directory helper only knows the current store.
     *
     * @param string[] $regionCountries countries whose regions are loaded
     * @return DirectoryData
     */
    public function getDirectoryData(int $storeId, array $regionCountries = []): array
    {
        $allowed = array_map('strval', array_keys(
            // @phpstan-ignore argument.type (the store id is an int in practice; Magento's docblock omits it)
            $this->allowedCountries->getAllowedCountries(ScopeInterface::SCOPE_STORE, $storeId)
        ));
        $postcodes = [];
        foreach ($this->postcodeConfig->getPostCodes() as $country => $patterns) {
            if (in_array((string)$country, $allowed, true)) {
                $postcodes[(string)$country] = array_values(array_map(
                    static fn (array $item): string => (string)$item['pattern'],
                    $patterns
                ));
            }
        }
        $regions = [];
        foreach (array_unique($regionCountries) as $country) {
            if (in_array($country, $allowed, true)) {
                $regions[$country] = $this->loadRegions($country);
            }
        }
        $lines = (int)$this->scopeConfig->getValue('customer/address/street_lines', ScopeInterface::SCOPE_STORE, $storeId);
        return [
            'allowedCountries' => $allowed,
            'regionsRequired' => $this->readCountryList('general/region/state_required', $storeId, $allowed),
            'optionalZip' => $this->readCountryList('general/country/optional_zip_countries', $storeId, $allowed),
            'postcodes' => $postcodes,
            'streetLines' => $lines >= 1 && $lines <= self::MAX_STREET_LINES ? $lines : 2,
            'regions' => $regions,
        ];
    }

    /**
     * Hard errors only; a postcode that fails the country patterns is a warning (see postcodeWarning).
     *
     * @param array<string, mixed> $value normalised address
     * @param array<string, mixed> $field
     * @param DirectoryData $directory
     */
    public function validate(array $value, array $field, array $directory): ?string
    {
        $country = (string)($value['country_id'] ?? '');
        if ($country === '' || !in_array($country, $directory['allowedCountries'] ?? [], true)) {
            return (string)__('Please choose a valid country.');
        }
        $street = (array)($value['street'] ?? []);
        if ($street === []) {
            return (string)__('Please enter a street address.');
        }
        if (count($street) > (int)($directory['streetLines'] ?? 1)) {
            return (string)__('Please use fewer street address lines.');
        }
        if ((string)($value['city'] ?? '') === '') {
            return (string)__('Please enter a city.');
        }
        if (!$this->isRegionValid($value, $country, $directory)) {
            return (string)__('Please choose or enter a region.');
        }
        if ((string)($value['postcode'] ?? '') === '' && !in_array($country, $directory['optionalZip'] ?? [], true)) {
            return (string)__('Please enter a postcode.');
        }
        return null;
    }

    /**
     * @param array<string, mixed> $value
     * @param DirectoryData $directory
     */
    public function postcodeWarning(array $value, array $directory): bool
    {
        $postcode = (string)($value['postcode'] ?? '');
        $patterns = $directory['postcodes'][(string)($value['country_id'] ?? '')] ?? [];
        if ($postcode === '' || $patterns === []) {
            return false;
        }
        foreach ($patterns as $pattern) {
            if (preg_match('/' . $pattern . '/', $postcode) === 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $value
     * @param DirectoryData $directory
     */
    private function isRegionValid(array $value, string $country, array $directory): bool
    {
        $regionId = (string)($value['region_id'] ?? '');
        $required = in_array($country, $directory['regionsRequired'] ?? [], true);
        $list = $directory['regions'][$country] ?? [];
        if ($list !== []) {
            return $regionId === '' ? !$required : isset($list[$regionId]);
        }
        return !$required || (string)($value['region'] ?? '') !== '';
    }

    /** @return array<string, string> */
    private function loadRegions(string $country): array
    {
        $regions = [];
        foreach ($this->regionCollectionFactory->create()->addCountryFilter($country) as $region) {
            $regions[(string)$region->getRegionId()] = (string)$region->getName();
        }
        return $regions;
    }

    /**
     * @param string[] $allowed
     * @return string[]
     */
    private function readCountryList(string $path, int $storeId, array $allowed): array
    {
        $raw = (string)$this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        $codes = array_filter(array_map(trim(...), explode(',', $raw)), static fn (string $c): bool => $c !== '');
        return array_values(array_intersect($codes, $allowed));
    }
}
