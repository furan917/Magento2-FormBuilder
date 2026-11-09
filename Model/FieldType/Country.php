<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface;
use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Api\FieldType\StatsLabelInterface;
use Magento\Directory\Model\AllowedCountries;
use Magento\Framework\Locale\ListsInterface;
use Magento\Store\Model\ScopeInterface;

class Country extends AbstractFieldType implements StatsAwareTypeInterface, AdminViewTypeInterface, StatsLabelInterface
{
    public function __construct(
        private readonly AllowedCountries $allowedCountries,
        private readonly ListsInterface $lists
    ) {
    }

    /** @var array<string, string>|null country code => name for the admin locale, loaded on first use */
    private ?array $names = null;

    public function getCode(): string
    {
        return 'country';
    }

    public function getLabel(): string
    {
        return (string)__('Country');
    }

    public function getCategory(): string
    {
        return 'choice';
    }

    public function validateValue(string|array $value, array $field, array $context): ?string
    {
        $allowed = $this->allowedCountries->getAllowedCountries(
            ScopeInterface::SCOPE_STORE,
            (int)($context['store_id'] ?? 0) // @phpstan-ignore argument.type (Magento types the scope code string|array|null; a store id works)
        );
        $codes = array_merge(array_keys($allowed), array_values($allowed));
        if (is_string($value) && in_array($value, $codes, true)) {
            return null;
        }
        return $this->getInvalidMessage($field);
    }

    /**
     * @param array<string, mixed> $field
     */
    public function getInvalidMessage(array $field): ?string
    {
        return (string)__('Please choose a valid option.');
    }

    public function statsKind(array $field): ?int
    {
        return self::KIND_CHOICE;
    }

    public function statsLabel(string $value, array $field): string
    {
        $this->names ??= array_column($this->lists->getOptionCountries(), 'label', 'value');
        return $this->names[$value] ?? $value;
    }

    public function adminViewKind(array $field): ?string
    {
        return self::KIND_COUNTRY;
    }
}
