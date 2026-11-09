<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Api\FieldType\ClientMessagesInterface;
use Furan\FormBuilder\Api\FieldType\ConditionSourceInterface;
use Furan\FormBuilder\Api\FieldType\DisplayableTypeInterface;
use Furan\FormBuilder\Api\FieldType\ExportableTypeInterface;
use Furan\FormBuilder\Api\FieldType\RoutableTypeInterface;
use Furan\FormBuilder\Api\FieldType\PersonalByDefaultInterface;
use Furan\FormBuilder\Api\FieldType\StorefrontConfigInterface;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Csp\MapMarker;
use Furan\FormBuilder\Model\Submission\ValueFormatter;
use Furan\FormBuilder\Model\Url\StorefrontUrls;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Escaper;
use Magento\Store\Model\ScopeInterface;

/**
 * Map pin posted as name[lat], name[lng], name[zoom], name[label]. Coordinates are stored as fixed
 * six-decimal strings so PHP and JS agree byte for byte. A malformed pin normalises to ['error' => code]
 * so validateValue can reject it.
 */
class Map extends AbstractFieldType implements
    ExportableTypeInterface,
    DisplayableTypeInterface,
    ClientMessagesInterface,
    StorefrontConfigInterface,
    PersonalByDefaultInterface,
    ConditionSourceInterface,
    RoutableTypeInterface,
    StatsAwareTypeInterface
{
    private const COORDINATE = '/^-?\d+(\.\d+)?$/D';
    private const MAX_ZOOM = 22;
    private const LEAFLET = 'Furan_FormBuilder::vendor/leaflet/1.9.4/';

    public function __construct(
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StorefrontUrls $urls,
        private readonly MapMarker $marker
    ) {
    }

    public function storefrontConfig(array $fields, int $storeId): array
    {
        $provider = $this->config->getMapProvider($storeId);
        $google = $provider === 'google';
        $tileUrl = $this->config->getTileUrl($storeId);
        $host = $google ? 'Google' : (string)parse_url((string)preg_replace('/\{[^}]*\}/', 'x', $tileUrl), PHP_URL_HOST);

        return ['maps' => [
            'provider' => $provider,
            'tileUrl' => $tileUrl,
            'attribution' => $this->config->getAttribution($storeId),
            'maxZoom' => $this->config->getMaxZoom($storeId),
            'assets' => [
                'leafletJs' => $this->urls->asset(self::LEAFLET . 'leaflet.js'),
                'leafletCss' => $this->urls->asset(self::LEAFLET . 'leaflet.css'),
                'imagePath' => dirname($this->urls->asset(self::LEAFLET . 'images/marker-icon.png')) . '/',
                'mapJs' => $this->urls->asset('Furan_FormBuilder::js/ffb-map.js'),
                'googleJs' => $this->urls->asset('Furan_FormBuilder::js/ffb-map-google.js'),
            ],
            'googleKey' => $google ? $this->config->getGoogleApiKey($storeId) : '',
            'mapId' => $google ? $this->config->getGoogleMapId($storeId) : '',
            'canvasMarker' => $this->marker->value($storeId),
            'locale' => (string)$this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $storeId),
            'searchUrl' => !$google && $this->config->isMapSearchEnabled($storeId)
                ? $this->urls->route('furanform/map/search') : '',
            'messages' => [
                'pinSet' => (string)__('Pin set at %1, %2', '%1', '%2'),
                'privacy' => (string)__(
                    'Loading the map sends your IP address to %1.',
                    $host !== '' ? $host : (string)__('the map provider')
                ),
                'loadError' => (string)__('The map could not be loaded. Please enter the coordinates instead.'),
            ],
        ]];
    }

    public function getCode(): string
    {
        return 'map';
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
        return (string)__('Map');
    }

    public function getCategory(): string
    {
        return 'advanced';
    }

    public function getSettingsSpec(): array
    {
        return [
            'default_lat' => ['type' => 'number', 'default' => 51.5, 'min' => -90, 'max' => 90],
            'default_lng' => ['type' => 'number', 'default' => 0, 'min' => -180, 'max' => 180],
            'default_zoom' => ['type' => 'int', 'default' => 5, 'min' => 1, 'max' => 19],
            'height' => ['type' => 'int', 'default' => 320, 'min' => 200, 'max' => 800],
        ];
    }

    public function normalizeValue(mixed $raw, array $field): string|array|null
    {
        if (!is_array($raw)) {
            return null;
        }
        $lat = $this->scalarToTrimmed($raw['lat'] ?? '');
        $lng = $this->scalarToTrimmed($raw['lng'] ?? '');
        if ($lat === '' && $lng === '') {
            return null;
        }
        if ($lat === '' || $lng === '') {
            return ['error' => 'incomplete'];
        }
        if (!preg_match(self::COORDINATE, $lat) || !preg_match(self::COORDINATE, $lng)
            || !is_finite((float)$lat) || !is_finite((float)$lng)
        ) {
            return ['error' => 'invalid'];
        }
        $label = parent::normalizeValue(is_scalar($raw['label'] ?? null) ? $raw['label'] : '', $field);
        return [
            'lat' => $this->format((float)$lat),
            'lng' => $this->format($this->wrapLongitude((float)$lng)),
            'zoom' => $this->normalizeZoom($raw['zoom'] ?? ''),
            'label' => is_string($label) ? $label : '',
        ];
    }

    public function validateValue(string|array $value, array $field, array $context): ?string
    {
        if (!is_array($value) || ($value['error'] ?? '') === 'invalid' || !isset($value['lat'], $value['lng'])) {
            return ($value['error'] ?? '') === 'incomplete' ? $this->getIncompleteMessage() : $this->getInvalidMessage($field);
        }
        return abs((float)$value['lat']) > 90 ? $this->getInvalidMessage($field) : null;
    }

    /**
     * @param array<string, mixed> $field
     */
    public function getInvalidMessage(array $field): ?string
    {
        return (string)__('Please choose a valid location.');
    }

    public function getIncompleteMessage(): string
    {
        return (string)__('Please enter both a latitude and a longitude.');
    }

    public function getClientMessages(array $field): array
    {
        return ['messages' => [
            'invalid' => (string)$this->getInvalidMessage($field),
            'incomplete' => $this->getIncompleteMessage(),
        ]];
    }

    protected function getMaxLength(): int
    {
        return 255;
    }

    private function scalarToTrimmed(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function wrapLongitude(float $lng): float
    {
        if ($lng >= -180 && $lng <= 180) {
            return $lng;
        }
        return fmod(fmod($lng + 180, 360) + 360, 360) - 180;
    }

    private function format(float $number): string
    {
        $text = sprintf('%.6f', $number);
        return $text === '-0.000000' ? '0.000000' : $text;
    }

    private function normalizeZoom(mixed $raw): ?int
    {
        $zoom = $this->scalarToTrimmed($raw);
        if (!preg_match('/^\d{1,2}$/D', $zoom) || (int)$zoom > self::MAX_ZOOM) {
            return null;
        }
        return (int)$zoom;
    }

    public function csvHeaders(array $field): array
    {
        $label = (string)($field['label'] ?? '');
        return [$label . ' (latitude)', $label . ' (longitude)', $label . ' (label)'];
    }

    public function csvCells(array $field, mixed $value): array
    {
        if (!is_array($value) || !ValueFormatter::hasCoordinates($value)) {
            return ['', '', ''];
        }
        $label = is_scalar($value['label'] ?? null) ? (string)$value['label'] : '';
        return [(string)$value['lat'], (string)$value['lng'], $label];
    }

    public function csvPlainColumns(array $field): array
    {
        return [0, 1];
    }

    public function toText(array $field, mixed $value): string
    {
        if (!is_array($value) || !ValueFormatter::hasCoordinates($value)) {
            return '';
        }
        $coordinates = $value['lat'] . ', ' . $value['lng'];
        $label = is_scalar($value['label'] ?? null) ? trim((string)$value['label']) : '';
        return $label !== '' ? $label . ' (' . $coordinates . ')' : $coordinates;
    }

    public function toHtml(array $field, mixed $value, Escaper $escaper): string
    {
        $html = $escaper->escapeHtml($this->toText($field, $value));
        $links = self::links($value);
        if ($links === null) {
            return $html;
        }
        return $html . ' <span class="ffb-sub-map-links">'
            . '<a href="' . $escaper->escapeUrl($links['osm']) . '" target="_blank" rel="noopener noreferrer">'
            . $escaper->escapeHtml(__('OpenStreetMap')) . '</a> '
            . '<a href="' . $escaper->escapeUrl($links['google']) . '" target="_blank" rel="noopener noreferrer">'
            . $escaper->escapeHtml(__('Google Maps')) . '</a></span>';
    }

    /** @return array{osm: string, google: string}|null null unless both coordinates are normalised */
    public static function links(mixed $value): ?array
    {
        if (!is_array($value) || !ValueFormatter::hasCoordinates($value)) {
            return null;
        }
        $lat = (string)$value['lat'];
        $lng = (string)$value['lng'];
        $zoom = min(19, max(1, (int)($value['zoom'] ?? 0) ?: 15));
        return [
            'osm' => 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lng . '#map=' . $zoom . '/' . $lat . '/' . $lng,
            'google' => 'https://www.google.com/maps/search/?api=1&query=' . $lat . ',' . $lng,
        ];
    }

    public function statsKind(array $field): ?int
    {
        return self::KIND_NONE;
    }
}
