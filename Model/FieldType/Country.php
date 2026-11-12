<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface;
use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Api\FieldType\StatsLabelInterface;
use Magento\Directory\Model\AllowedCountries;
use Magento\Framework\Locale\ListsInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Store\Model\ScopeInterface;
use Furan\FormBuilder\Api\FieldType\BuilderControlsInterface;

class Country extends AbstractFieldType implements StatsAwareTypeInterface, AdminViewTypeInterface, StatsLabelInterface, BuilderControlsInterface
{
    public function __construct(
        private readonly AllowedCountries $allowedCountries,
        private readonly ListsInterface $lists,
        private readonly ResolverInterface $localeResolver
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
        return $this->names()[$value] ?? $value;
    }

    /** Any Magento country code: the store views decide at render which ones they offer. */
    public function isValidDefault(string $default, array $field): bool
    {
        return isset($this->names()[$default]);
    }

    /** @return array<string, string> */
    private function names(): array
    {
        if ($this->names === null) {
            // The locale list also holds UN M49 region codes such as 150 (Europe), which are not countries.
            $names = array_filter(
                array_column($this->lists->getOptionCountries(), 'label', 'value'),
                static fn (mixed $code): bool => preg_match('/^[A-Z]{2}$/D', (string)$code) === 1,
                ARRAY_FILTER_USE_KEY
            );
            $this->names = $this->sortByLabel(array_map('strval', $names));
        }
        return $this->names;
    }

    /**
     * Locale collation puts "Åland Islands" after "Afghanistan"; without intl the list keeps its order.
     *
     * @param array<string, string> $names
     * @return array<string, string>
     */
    private function sortByLabel(array $names): array
    {
        if (!class_exists(\Collator::class)) {
            return $names;
        }
        $locale = (string)$this->localeResolver->getLocale();
        $collator = new \Collator($locale);
        $sorted = $names;
        return $collator->asort($sorted) ? $sorted : $names;
    }

    public function adminViewKind(array $field): ?string
    {
        return self::KIND_COUNTRY;
    }

    public function builderControls(): array
    {
        return ['placeholder' => false];
    }

    public function defaultChoices(): array
    {
        return $this->names();
    }

    public function defaultInput(): ?string
    {
        return 'option';
    }
}
