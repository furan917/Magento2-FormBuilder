<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Api\FieldType\ClientMessagesInterface;
use Furan\FormBuilder\Api\FieldType\ConditionSourceInterface;
use Furan\FormBuilder\Api\FieldType\DisplayableTypeInterface;
use Furan\FormBuilder\Api\FieldType\ExportableTypeInterface;
use Furan\FormBuilder\Api\FieldType\RenderHintsInterface;
use Furan\FormBuilder\Api\FieldType\RoutableTypeInterface;
use Furan\FormBuilder\Api\FieldType\PersonalByDefaultInterface;
use Furan\FormBuilder\Api\FieldType\StorefrontConfigInterface;
use Furan\FormBuilder\Model\Directory\CountryName;
use Furan\FormBuilder\Model\Submission\ValueFormatter;
use Furan\FormBuilder\Model\Url\StorefrontUrls;
use Magento\Framework\Escaper;

use Furan\FormBuilder\Model\Directory\AddressRules;

class Address extends AbstractFieldType implements
    ExportableTypeInterface,
    DisplayableTypeInterface,
    ClientMessagesInterface,
    StorefrontConfigInterface,
    RenderHintsInterface,
    PersonalByDefaultInterface,
    ConditionSourceInterface,
    RoutableTypeInterface,
    StatsAwareTypeInterface
{
    private const MAX_PART = 255;
    private const MAX_STREET_LINES = 4;

    public function __construct(private readonly AddressRules $rules, private readonly StorefrontUrls $urls)
    {
    }

    /** @var array<int, array<string, mixed>> directory data per store view, read once per request */
    private array $directories = [];

    /** @return array{street_lines: int} */
    public function renderHints(array $field, int $storeId): array
    {
        return ['street_lines' => $this->directory($storeId)['streetLines']];
    }

    public function storefrontConfig(array $fields, int $storeId): array
    {
        $directory = $this->directory($storeId);
        // Regions come from the regions endpoint, one country at a time.
        unset($directory['regions']);
        return ['directory' => $directory, 'regionsUrl' => $this->urls->route('furanform/directory/regions')];
    }

    /**
     * @return array<string, mixed>
     */
    private function directory(int $storeId): array
    {
        return $this->directories[$storeId] ??= $this->rules->getDirectoryData($storeId);
    }

    public function getCode(): string
    {
        return 'address';
    }

    public function canBeConditionSource(): bool
    {
        return false;
    }

    public function canBeRouted(): bool
    {
        return false;
    }

    public function isPersonalByDefault(): bool
    {
        return true;
    }

    public function getLabel(): string
    {
        return (string)__('Address');
    }

    public function normalizeValue(mixed $raw, array $field): string|array|null
    {
        if (!is_array($raw)) {
            return null;
        }
        $lines = [];
        foreach ((array)($raw['street'] ?? []) as $line) {
            $line = $this->clean($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        $regionId = $this->clean($raw['region_id'] ?? '');
        $postcode = strtoupper((string)preg_replace('/ {2,}/', ' ', $this->clean($raw['postcode'] ?? '')));
        $value = [
            'street' => array_slice($lines, 0, self::MAX_STREET_LINES),
            'city' => $this->clean($raw['city'] ?? ''),
            'region_id' => preg_match('/^\d{1,10}$/', $regionId) === 1 ? $regionId : '',
            'region' => $this->clean($raw['region'] ?? ''),
            'postcode' => mb_substr($postcode, 0, self::MAX_PART),
            'country_id' => $this->clean($raw['country_id'] ?? ''),
        ];
        $isBlank = $value['street'] === [] && $value['city'] === '' && $value['region_id'] === ''
            && $value['region'] === '' && $value['postcode'] === '';
        return $isBlank ? null : $value;
    }

    public function validateValue(string|array $value, array $field, array $context): ?string
    {
        if (!is_array($value)) {
            return (string)__('Please choose a valid country.');
        }
        $directory = $this->rules->getDirectoryData(
            (int)($context['store_id'] ?? 0),
            [(string)($value['country_id'] ?? '')]
        );
        /** @var array<string, mixed> $value */
        return $this->rules->validate($value, $field, $directory);
    }

    public function getClientMessages(array $field): array
    {
        return ['messages' => [
            'country' => (string)__('Please choose a valid country.'),
            'street' => (string)__('Please enter a street address.'),
            'city' => (string)__('Please enter a city.'),
            'region' => (string)__('Please choose or enter a region.'),
            'postcode' => (string)__('Please enter a postcode.'),
            'lines' => (string)__('Please use fewer street address lines.'),
            'postcode_hint' => (string)__(
                'This postcode does not look valid for the selected country. Please check it.'
            ),
        ]];
    }

    private function clean(mixed $raw): string
    {
        if (!is_scalar($raw)) {
            return '';
        }
        $text = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', (string)$raw) ?? '';
        return mb_substr(trim(str_replace(["\n", "\t"], ' ', $text)), 0, self::MAX_PART);
    }

    public function csvHeaders(array $field): array
    {
        return [(string)($field['label'] ?? '')];
    }

    public function csvCells(array $field, mixed $value): array
    {
        return [$this->toText($field, $value)];
    }

    public function csvPlainColumns(array $field): array
    {
        return [];
    }

    public function toText(array $field, mixed $value): string
    {
        if (!is_array($value)) {
            return ValueFormatter::flatten($value);
        }
        $parts = [];
        foreach ((array)($value['street'] ?? []) as $line) {
            $parts[] = $line;
        }
        array_push($parts, $value['city'] ?? '', $value['region'] ?? '', $value['postcode'] ?? '');
        $country = is_scalar($value['country_id'] ?? null) ? (string)$value['country_id'] : '';
        $parts[] = $country !== '' ? CountryName::of($country) : '';
        return implode(', ', array_filter(array_map(
            static fn($part): string => is_scalar($part) ? trim((string)$part) : '',
            $parts
        ), static fn(string $part): bool => $part !== ''));
    }

    public function toHtml(array $field, mixed $value, Escaper $escaper): string
    {
        return $escaper->escapeHtml($this->toText($field, $value));
    }

    public function statsKind(array $field): ?int
    {
        return self::KIND_ADDRESS;
    }
}
