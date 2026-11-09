<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Csp;

use Furan\FormBuilder\Model\Config;
use Magento\Csp\Model\Policy\FetchPolicy;

/** The hosts the map field of a store view loads from. */
class MapPolicies
{
    private const HOST_PATTERN = '/^(\*\.)?[a-z0-9-]+(\.[a-z0-9-]+)+(:\d+)?$/';

    private const GOOGLE_HOSTS = [
        'script-src' => ['*.googleapis.com', '*.gstatic.com', '*.google.com', '*.ggpht.com', '*.googleusercontent.com'],
        'img-src' => ['*.googleapis.com', '*.gstatic.com', '*.google.com', '*.googleusercontent.com'],
        'connect-src' => ['*.googleapis.com', '*.google.com', '*.gstatic.com'],
        'font-src' => ['fonts.gstatic.com'],
        'style-src' => ['fonts.googleapis.com'],
        'frame-src' => ['*.google.com'],
    ];

    private const GOOGLE_SCHEMES = [
        'script-src' => ['blob'],
        'connect-src' => ['data', 'blob'],
    ];

    public function __construct(private readonly Config $config)
    {
    }

    /** @return FetchPolicy[] */
    public function forStore(int $storeId): array
    {
        if ($this->config->getMapProvider($storeId) === 'google') {
            return $this->config->getGoogleApiKey($storeId) === '' ? [] : $this->googlePolicies();
        }

        $host = $this->tileHost($this->config->getTileUrl($storeId));

        return $host === null ? [] : [$this->policy('img-src', [$host])];
    }

    /** @return FetchPolicy[] */
    private function googlePolicies(): array
    {
        $policies = [];
        foreach (self::GOOGLE_HOSTS as $id => $hosts) {
            $policies[] = $this->policy($id, $hosts, self::GOOGLE_SCHEMES[$id] ?? []);
        }

        return $policies;
    }

    /**
     * @param string[] $hosts
     * @param string[] $schemes
     */
    private function policy(string $id, array $hosts, array $schemes = []): FetchPolicy
    {
        return new FetchPolicy($id, false, $hosts, $schemes, false, false, false);
    }

    private function tileHost(string $url): ?string
    {
        $url = str_replace('{s}.', '*.', $url);
        $url = str_replace(['{z}', '{x}', '{y}', '{r}', '{-y}'], '', $url);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        if (!is_string($host)) {
            return null;
        }
        $host = strtolower($host) . ($port !== null && $port !== false ? ':' . $port : '');

        return preg_match(self::HOST_PATTERN, $host) === 1 ? $host : null;
    }
}
