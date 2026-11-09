<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Map;

use Furan\FormBuilder\Model\Clock;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Api\Data\RequestPolicy;
use Furan\FormBuilder\Model\Http\SafeHttpClient;
use Furan\FormBuilder\Api\Data\SafeUrl;
use Furan\FormBuilder\Model\Http\UnsafeUrlException;
use Furan\FormBuilder\Model\Http\UrlNormalizer;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;

/**
 * Upstream OSM Nominatim search, at most one upstream call per second site-wide. Callers enforce per-visitor limits:
 * https://operations.osmfoundation.org/policies/nominatim/
 */
class NominatimClient
{
    private const CACHE_PREFIX = 'furan_ffb_geo_';
    private const LOCK_NAME = 'furan_ffb_geo';
    // In the general cache: a disabled geo cache type must not switch the throttle off.
    private const LAST_CALL_KEY = 'furan_ffb_geo_last_call';
    private const MIN_INTERVAL_SECONDS = 1.0;
    private const CACHE_TTL = 604800;
    private const USER_AGENT = 'Furan-FormBuilder/1.0 (+https://github.com/furan917/magento2-formbuilder)';
    private const MAX_RESULTS = 5;
    private const MAX_LABEL_LENGTH = 255;
    private const COORDINATE_PATTERN = '/^-?\d+(\.\d+)?$/D';

    public function __construct(
        private readonly SafeHttpClient $httpClient,
        private readonly UrlNormalizer $urlNormalizer,
        private readonly MapRequestPolicy $requestPolicy,
        private readonly GeoCache $resultCache,
        private readonly CacheInterface $cache,
        private readonly LockManagerInterface $lockManager,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Json $json
    ) {
    }

    /**
     * @return array<int, array{label: string, lat: string, lon: string}>|null null on upstream failure
     * @throws GeocoderBusyException when the query is not cached and the upstream slot is taken
     */
    public function search(string $query, ?int $storeId = null): ?array
    {
        $query = trim((string)preg_replace('/\s+/u', ' ', $query));
        $lang = $this->language($storeId);
        $endpoint = $this->config->getMapSearchEndpoint($storeId);
        $key = self::CACHE_PREFIX . hash('sha256', mb_strtolower($query) . '|' . $lang . '|' . $endpoint);

        $cached = $this->resultCache->load($key);
        if (is_string($cached) && $cached !== '') {
            /** @var array<int, array{label: string, lat: string, lon: string}> $hit */
            $hit = $this->json->unserialize($cached);
            return $hit;
        }

        $results = $this->throttled(fn (): ?array => $this->fetch($query, $lang, $endpoint));
        if ($results !== null) {
            $this->resultCache->save((string)$this->json->serialize($results), $key, [], self::CACHE_TTL);
        }

        return $results;
    }

    /**
     * @param callable(): (array<int, array{label: string, lat: string, lon: string}>|null) $fetch
     * @return array<int, array{label: string, lat: string, lon: string}>|null
     * @throws GeocoderBusyException
     */
    private function throttled(callable $fetch): ?array
    {
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            throw new GeocoderBusyException();
        }
        try {
            $now = $this->clock->now();
            if ($now - (float)$this->cache->load(self::LAST_CALL_KEY) < self::MIN_INTERVAL_SECONDS) {
                throw new GeocoderBusyException();
            }
            $this->cache->save((string)$now, self::LAST_CALL_KEY, [], 60);
            return $fetch();
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /**
     * @return array<int, array{label: string, lat: string, lon: string}>|null
     */
    private function fetch(string $query, string $lang, string $endpoint): ?array
    {
        $policy = $this->requestPolicy->build();
        $url = $this->searchUrl($query, $policy, $endpoint);
        if ($url === null) {
            return null;
        }

        $result = $this->httpClient->request('GET', $url, $policy, [
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'application/json',
            'Accept-Language' => $lang,
        ]);
        if ($result->errorClass !== null || $result->bodyTruncated || $result->status !== 200) {
            return null;
        }
        $body = $result->body;

        try {
            $rows = $this->json->unserialize($body);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return is_array($rows) ? $this->mapRows($rows) : null;
    }

    private function searchUrl(string $query, RequestPolicy $policy, string $endpoint): ?SafeUrl
    {
        $queryString = http_build_query([
            'q' => $query,
            'format' => 'jsonv2',
            'limit' => self::MAX_RESULTS,
            'addressdetails' => 0,
        ], '', '&', PHP_QUERY_RFC3986);

        // No fallback to the public default: a store that set its own endpoint must never leak searches to a third party.
        try {
            return $this->urlNormalizer->normalize(
                $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $queryString,
                $policy
            );
        } catch (UnsafeUrlException) {
            // Save-time validation normally prevents this.
            return null;
        }
    }

    /**
     * @param array<mixed> $rows
     * @return array<int, array{label: string, lat: string, lon: string}>
     */
    private function mapRows(array $rows): array
    {
        $results = [];
        foreach ($rows as $row) {
            $result = is_array($row) ? $this->mapRow($row) : null;
            if ($result !== null) {
                $results[] = $result;
            }
            if (count($results) >= self::MAX_RESULTS) {
                break;
            }
        }

        return $results;
    }

    /**
     * @param array<mixed> $row
     * @return array{label: string, lat: string, lon: string}|null
     */
    private function mapRow(array $row): ?array
    {
        $lat = $this->coordinate($row['lat'] ?? null, 90);
        $lon = $this->coordinate($row['lon'] ?? null, 180);
        if ($lat === null || $lon === null) {
            return null;
        }

        $label = is_string($row['display_name'] ?? null) ? $row['display_name'] : '';
        $label = (string)preg_replace('/[\x00-\x1F\x7F]+/', '', $label);

        return ['label' => mb_substr($label, 0, self::MAX_LABEL_LENGTH), 'lat' => $lat, 'lon' => $lon];
    }

    private function coordinate(mixed $raw, int $limit): ?string
    {
        if (!is_string($raw) || !preg_match(self::COORDINATE_PATTERN, $raw) || abs((float)$raw) > $limit) {
            return null;
        }

        return number_format((float)$raw, 6, '.', '');
    }

    private function language(?int $storeId): string
    {
        $locale = (string)$this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $storeId);
        $lang = strtolower(explode('_', $locale)[0]);

        return preg_match('/^[a-z]{2,3}$/', $lang) ? $lang : 'en';
    }
}
