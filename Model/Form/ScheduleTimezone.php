<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Form;

use Magento\Config\Model\Config\Source\Locale\Timezone;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Supplies the schedule timezone for a form that has none stored yet. The admin's stored choice always wins
 * and is never recomputed from the store views.
 */
class ScheduleTimezone
{
    private const PATH = 'general/locale/timezone';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly Timezone $timezoneSource
    ) {
    }

    /**
     * The shared zone of the assigned store views (store 0 = all of them), else the default-scope zone.
     *
     * @param int[] $storeIds
     */
    public function defaultFor(array $storeIds): string
    {
        $zones = [];
        foreach ($this->storeZones() as $id => $zone) {
            if ($storeIds === [] || in_array(0, $storeIds, true) || in_array($id, $storeIds, true)) {
                $zones[$zone] = true;
            }
        }
        return count($zones) === 1 ? (string)array_key_first($zones) : $this->defaultScope();
    }

    /** @return array<int, string> store view id => zone */
    public function storeZones(): array
    {
        $zones = [];
        foreach ($this->storeManager->getStores() as $store) {
            $id = (int)$store->getId();
            $zone = $this->scopeConfig->getValue(self::PATH, ScopeInterface::SCOPE_STORE, $id);
            if (is_string($zone) && $zone !== '') {
                $zones[$id] = $zone;
            }
        }
        return $zones;
    }

    public function defaultScope(): string
    {
        $zone = $this->scopeConfig->getValue(self::PATH);
        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }

    /**
     * Magento's zone list limited to IANA ids (the only values a save accepts); a zone a form or store already
     * uses is added when missing so the select can show it.
     *
     * @param string[] $inUse
     * @return list<array{value: string, label: string}>
     */
    public function options(array $inUse = []): array
    {
        $options = [];
        foreach ($this->timezoneSource->toOptionArray() as $option) {
            $value = $option['value'] ?? null;
            if (is_string($value) && Schedule::isIdentifier($value)) {
                $options[$value] = ['value' => $value, 'label' => (string)($option['label'] ?? $value)];
            }
        }
        foreach ($inUse as $zone) {
            if ($zone !== '' && !isset($options[$zone])) {
                $options[$zone] = ['value' => $zone, 'label' => $zone];
            }
        }
        return array_values($options);
    }
}
