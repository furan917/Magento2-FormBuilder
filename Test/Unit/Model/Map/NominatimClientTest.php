<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Map;

use Furan\FormBuilder\Model\Clock;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Http\PublicAddress;
use Furan\FormBuilder\Model\Http\SafeHttpClient;
use Furan\FormBuilder\Model\Http\UrlNormalizer;
use Furan\FormBuilder\Model\Map\GeoCache;
use Furan\FormBuilder\Model\Map\GeocoderBusyException;
use Furan\FormBuilder\Model\Map\MapRequestPolicy;
use Furan\FormBuilder\Model\Map\NominatimClient;
use Furan\FormBuilder\Test\Unit\Model\Http\FakeCurlSession;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class NominatimClientTest extends TestCase
{
    private const ENDPOINT = 'https://nominatim.example/search';

    private FakeCurlSession $session;
    private GeoCache&Stub $cache;
    private ?LockManagerInterface $lock = null;
    /** @var array<string, string> */
    private array $appCache = [];
    private float $now = 1000.0;
    private Config&Stub $config;
    private ScopeConfigInterface&Stub $scopeConfig;
    private NominatimClient $client;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?GeoCache $cache = null): void
    {
        $this->session = new FakeCurlSession();
        $this->cache = $cache ?? $this->createStub(GeoCache::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('getMapSearchEndpoint')->willReturn(self::ENDPOINT);
        $this->scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $this->scopeConfig->method('getValue')->willReturn('de_DE');
        $this->client = $this->clientFor(['nominatim.example' => ['93.184.216.34']]);
    }

    /** @param array<string,string[]> $dns */
    private function clientFor(array $dns, ?DeploymentConfig $deployment = null): NominatimClient
    {
        $appCache = $this->createStub(CacheInterface::class);
        $appCache->method('load')->willReturnCallback(fn (string $k) => $this->appCache[$k] ?? false);
        $appCache->method('save')->willReturnCallback(function (string $d, string $k): bool {
            $this->appCache[$k] = $d;
            return true;
        });
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturnCallback(fn (): float => $this->now);
        if ($this->lock === null) {
            $this->lock = $this->createStub(LockManagerInterface::class);
            $this->lock->method('lock')->willReturn(true);
        }

        return new NominatimClient(
            new SafeHttpClient($this->session, new PublicAddress(fn (string $host): array => $dns[$host] ?? [])),
            new UrlNormalizer(),
            new MapRequestPolicy($deployment ?? $this->createStub(DeploymentConfig::class)),
            $this->cache,
            $appCache,
            $this->lock,
            $clock,
            $this->config,
            $this->scopeConfig,
            new Json()
        );
    }

    private function respond(string $body, int $status = 200): void
    {
        $this->session->status = $status;
        $this->session->script = function (FakeCurlSession $s) use ($body): bool {
            $s->write($body);
            return true;
        };
    }

    public function testItServesRepeatedQueriesFromCacheWithoutCallingNominatim(): void
    {
        $this->cache->method('load')->willReturn('[{"label":"A","lat":"1.000000","lon":"2.000000"}]');

        $this->assertSame(
            [['label' => 'A', 'lat' => '1.000000', 'lon' => '2.000000']],
            $this->client->search('Berlin')
        );
        $this->assertSame(0, $this->session->opened);
    }

    public function testItCachesFreshResultsInTheGeoCacheTypeWithTtl(): void
    {
        $cache = $this->createMock(GeoCache::class);
        $this->fixture(cache: $cache);
        $this->cache->method('load')->willReturn(false);
        $this->respond('[]');
        $cache->expects($this->once())->method('save')
            ->with('[]', $this->anything(), [], 604800);

        $this->assertSame([], $this->client->search('Berlin'));
    }

    public function testItSharesCacheKeyAcrossCaseAndWhitespace(): void
    {
        $keys = [];
        $this->cache->method('load')->willReturnCallback(function (string $k) use (&$keys) {
            $keys[] = $k;
            return '[]';
        });
        $this->client->search("  New   YORK ");
        $this->client->search('new york');

        $this->assertSame($keys[0], $keys[1]);
    }

    public function testItKeysTheMapSearchCacheByTheEndpointAsWellAsTheQueryAndLanguage(): void
    {
        $keys = [];
        $this->cache->method('load')->willReturnCallback(function (string $k) use (&$keys) {
            $keys[] = $k;
            return '[]';
        });
        $this->config = $this->createStub(Config::class);
        $this->config->method('getMapSearchEndpoint')->willReturnMap([
            [1, self::ENDPOINT],
            [2, 'https://other.example/search'],
        ]);
        $client = $this->clientFor([]);
        $client->search('berlin', 1);
        $client->search('berlin', 2);

        $this->assertNotSame($keys[0], $keys[1]);
    }

    public function testItSendsAddressSearchesThroughTheHardenedClient(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->respond('[]');

        $this->client->search('Berlin');

        $o = $this->session->options;
        $this->assertSame(['::93.184.216.34:443'], $o[CURLOPT_CONNECT_TO]);
        $this->assertSame('', $o[CURLOPT_PROXY]);
        $this->assertFalse($o[CURLOPT_FOLLOWLOCATION]);
        $this->assertSame(CURLPROTO_HTTPS, $o[CURLOPT_PROTOCOLS]);
        $this->assertSame(5000, $o[CURLOPT_CONNECTTIMEOUT_MS]);
        $this->assertSame(5000, $o[CURLOPT_TIMEOUT_MS]);
        $this->assertTrue($o[CURLOPT_HTTPGET]);
        $this->assertStringStartsWith(self::ENDPOINT . '?', $o[CURLOPT_URL]);
        $this->assertStringContainsString('format=jsonv2', $o[CURLOPT_URL]);
        $this->assertStringContainsString('limit=5', $o[CURLOPT_URL]);
        $this->assertStringContainsString('addressdetails=0', $o[CURLOPT_URL]);
        $this->assertStringContainsString('q=Berlin', $o[CURLOPT_URL]);
        $headers = implode("\n", $o[CURLOPT_HTTPHEADER]);
        $this->assertMatchesRegularExpression('/^User-Agent: Furan-FormBuilder\/\S+/m', $headers);
        $this->assertStringContainsString('Accept-Language: de', $headers);
        $this->assertStringContainsString('Accept: application/json', $headers);
    }

    public function testItAllowsExtraMapPortsFromEnvPhpOnly(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->config = $this->createStub(Config::class);
        $this->config->method('getMapSearchEndpoint')->willReturn('https://nominatim.example:8443/search');
        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(
            static fn (string $path, $default = null) => $path === 'furan_formbuilder/map/ports'
                ? [8443, 'junk', 70000] : $default
        );
        $client = $this->clientFor(['nominatim.example' => ['93.184.216.34']], $deployment);
        $this->respond('[]');
        $this->assertSame([], $client->search('Berlin'));
        $this->assertSame(['::93.184.216.34:8443'], $this->session->options[CURLOPT_CONNECT_TO]);

        $this->session = new FakeCurlSession();
        $this->now += 1;
        $this->assertNull($this->clientFor(['nominatim.example' => ['93.184.216.34']])->search('Berlin2'));
        $this->assertSame(0, $this->session->opened);
    }

    public function testItReturnsOnlyLabelLatitudeAndLongitudeForEachResult(): void
    {
        $this->cache->method('load')->willReturn(false);
        $body = json_encode([[
            'place_id' => 1, 'display_name' => "Ber\x00lin\n, Germany", 'lat' => '52.5200066',
            'lon' => '13.404954', 'extra' => 'x',
        ]]);
        $this->respond($body);

        $this->assertSame(
            [['label' => 'Berlin, Germany', 'lat' => '52.520007', 'lon' => '13.404954']],
            $this->client->search('Berlin')
        );
    }

    public function testItDropsResultsWhoseCoordinatesAreNotValidNumbersInRange(): void
    {
        $this->cache->method('load')->willReturn(false);
        $rows = [
            ['display_name' => 'ok', 'lat' => '-90', 'lon' => '180'],
            ['display_name' => 'lat range', 'lat' => '90.5', 'lon' => '0'],
            ['display_name' => 'lon range', 'lat' => '0', 'lon' => '-180.1'],
            ['display_name' => 'text', 'lat' => 'abc', 'lon' => '0'],
            ['display_name' => 'sci', 'lat' => '1e1', 'lon' => '0'],
            ['display_name' => 'missing', 'lon' => '0'],
            ['display_name' => 'array', 'lat' => ['1'], 'lon' => '0'],
        ];
        $this->respond((string)json_encode($rows));

        $this->assertSame(
            [['label' => 'ok', 'lat' => '-90.000000', 'lon' => '180.000000']],
            $this->client->search('x')
        );
    }

    public function testItReturnsNullAndCachesNothingOnUpstreamFailure(): void
    {
        $cache = $this->createMock(GeoCache::class);
        $this->fixture(cache: $cache);
        $this->cache->method('load')->willReturn(false);
        $cache->expects($this->never())->method('save');
        $this->respond('[]', 503);

        $this->assertNull($this->client->search('x'));
    }

    public function testItStopsReadingAnOversizedSearchResponse(): void
    {
        $cache = $this->createMock(GeoCache::class);
        $this->fixture(cache: $cache);
        $this->cache->method('load')->willReturn(false);
        $cache->expects($this->never())->method('save');
        $accepted = null;
        $this->session->status = 200;
        $this->session->errno = 23;
        $this->session->script = function (FakeCurlSession $s) use (&$accepted): bool {
            $accepted = $s->write(str_repeat('a', 262145));
            return false;
        };

        $this->assertNull($this->client->search('x'));
        $this->assertSame(0, $accepted);
    }

    public function testItReturnsNullForAnInvalidBody(): void
    {
        $cache = $this->createMock(GeoCache::class);
        $this->fixture(cache: $cache);
        $this->cache->method('load')->willReturn(false);
        $cache->expects($this->never())->method('save');
        $this->respond('not json');

        $this->assertNull($this->client->search('y'));
    }

    public function testItLimitsResultsToFive(): void
    {
        $this->cache->method('load')->willReturn(false);
        $rows = array_fill(0, 8, ['display_name' => 'a', 'lat' => '1', 'lon' => '1']);
        $this->respond((string)json_encode($rows));

        $this->assertCount(5, $this->client->search('x'));
    }

    public function testItNeverFollowsAnUpstreamRedirect(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->respond('', 302);

        $this->assertNull($this->client->search('Berlin'));
        $this->assertFalse($this->session->options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testItRefusesAnEndpointThatNowResolvesToAnInternalAddress(): void
    {
        $this->cache->method('load')->willReturn(false);
        $client = $this->clientFor(['nominatim.example' => ['169.254.169.254']]);

        $this->assertNull($client->search('Berlin'));
        $this->assertSame(0, $this->session->opened);
    }

    public function testItNeverFallsBackToThePublicEndpointWhenTheConfiguredOneFailsThePolicy(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->config = $this->createStub(Config::class);
        $this->config->method('getMapSearchEndpoint')->willReturn('https://10.0.0.1/search');
        $client = $this->clientFor(['nominatim.openstreetmap.org' => ['130.117.76.9']]);

        $this->assertNull($client->search('Berlin'));
        $this->assertSame(0, $this->session->opened);
    }

    public function testItAllowsOneUpstreamCallPerSecondSiteWide(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->respond('[]');
        $this->assertSame([], $this->client->search('Berlin'));

        $this->now += 0.5;
        try {
            $this->client->search('Hamburg');
            $this->fail('Expected the second call within a second to be throttled.');
        } catch (GeocoderBusyException) {
        }
        $this->assertSame(1, $this->session->opened);

        $this->now += 0.6;
        $this->assertSame([], $this->client->search('Hamburg'));
        $this->assertSame(2, $this->session->opened);
    }

    public function testItServesCachedResultsWithoutTakingTheUpstreamSlot(): void
    {
        $lock = $this->createMock(LockManagerInterface::class);
        $lock->expects($this->never())->method('lock');
        $this->lock = $lock;
        $this->fixture();
        $this->cache->method('load')->willReturn('[]');

        $this->assertSame([], $this->client->search('Berlin'));
    }

    public function testItReportsBusyWithoutWaitingWhenAnotherRequestHoldsTheLock(): void
    {
        $lock = $this->createMock(LockManagerInterface::class);
        $lock->expects($this->once())->method('lock')->with('furan_ffb_geo', 0)->willReturn(false);
        $lock->expects($this->never())->method('unlock');
        $this->lock = $lock;
        $this->fixture();
        $this->cache->method('load')->willReturn(false);

        $this->expectException(GeocoderBusyException::class);
        try {
            $this->client->search('Berlin');
        } finally {
            $this->assertSame(0, $this->session->opened);
        }
    }

    public function testItReleasesTheLockAfterAFailedUpstreamCall(): void
    {
        $lock = $this->createMock(LockManagerInterface::class);
        $lock->method('lock')->willReturn(true);
        $lock->expects($this->once())->method('unlock')->with('furan_ffb_geo');
        $this->lock = $lock;
        $this->fixture();
        $this->cache->method('load')->willReturn(false);
        $this->respond('', 500);

        $this->assertNull($this->client->search('Berlin'));
    }
}
