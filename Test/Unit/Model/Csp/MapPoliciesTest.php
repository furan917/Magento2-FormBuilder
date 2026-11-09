<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Csp;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Csp\MapPolicies;
use Magento\Csp\Model\Policy\FetchPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MapPoliciesTest extends TestCase
{
    private const OSM = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

    /** @return array<string, FetchPolicy> */
    private function collect(string $provider, string $tileUrl = self::OSM, string $key = ''): array
    {
        $config = $this->createMock(Config::class);
        $config->method('getMapProvider')->with(3)->willReturn($provider);
        $config->method('getTileUrl')->with(3)->willReturn($tileUrl);
        $config->method('getGoogleApiKey')->with(3)->willReturn($key);

        $byId = [];
        foreach ((new MapPolicies($config))->forStore(3) as $policy) {
            $byId[$policy->getId()] = $policy;
        }

        return $byId;
    }

    public function testItAddsTheTileHostToImgSrcForOpenStreetMap(): void
    {
        $policies = $this->collect('osm');

        $this->assertSame(['img-src'], array_keys($policies));
        $this->assertSame(['tile.openstreetmap.org'], $policies['img-src']->getHostSources());
    }

    public function testItUsesAWildcardForTheSubdomainPlaceholder(): void
    {
        $policies = $this->collect('osm', 'https://{s}.tile.example.org/{z}/{x}/{y}{r}.png');

        $this->assertSame(['*.tile.example.org'], $policies['img-src']->getHostSources());
    }

    public function testItAddsTheDocumentedGoogleHostsForTheGoogleProvider(): void
    {
        $policies = $this->collect('google', self::OSM, 'KEY');

        $this->assertEqualsCanonicalizing(
            ['script-src', 'img-src', 'connect-src', 'font-src', 'style-src', 'frame-src'],
            array_keys($policies)
        );
        $this->assertContains('*.googleapis.com', $policies['script-src']->getHostSources());
        $this->assertContains('*.ggpht.com', $policies['script-src']->getHostSources());
        $this->assertContains('blob', $policies['script-src']->getSchemeSources());
        $this->assertContains('*.google.com', $policies['frame-src']->getHostSources());
        $this->assertSame(['fonts.gstatic.com'], $policies['font-src']->getHostSources());
        $this->assertSame(['fonts.googleapis.com'], $policies['style-src']->getHostSources());
        $this->assertEqualsCanonicalizing(['data', 'blob'], $policies['connect-src']->getSchemeSources());
        $this->assertNotContains('tile.openstreetmap.org', $policies['img-src']->getHostSources());
    }

    public function testItAddsNothingForGoogleWithoutAKey(): void
    {
        $this->assertSame([], $this->collect('google', self::OSM, ''));
    }

    public function testItNeverAddsNoneUnsafeEvalOrUnsafeInline(): void
    {
        foreach ([$this->collect('osm'), $this->collect('google', self::OSM, 'KEY')] as $policies) {
            foreach ($policies as $policy) {
                $this->assertFalse($policy->isNoneAllowed(), $policy->getId());
                $this->assertFalse($policy->isInlineAllowed(), $policy->getId());
                $this->assertFalse($policy->isEvalAllowed(), $policy->getId());
            }
        }
    }

    #[DataProvider('badTileUrls')]
    public function testItAddsNothingWhenTheTileHostIsNotAPlainHostName(string $url): void
    {
        $this->assertSame([], $this->collect('osm', $url));
    }

    public static function badTileUrls(): array
    {
        return [
            'directive injection' => ['https://a;script-src */{z}/{x}/{y}.png'],
            'space' => ['https://a b.example.com/{z}.png'],
            'no dot' => ['https://localhost/{z}/{x}/{y}.png'],
            'bare wildcard' => ['https://*/{z}.png'],
            'quote' => ["https://a'.example.com/{z}.png"],
            'empty' => [''],
        ];
    }
}
