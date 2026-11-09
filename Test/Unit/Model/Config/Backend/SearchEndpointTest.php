<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Config\Backend;

use Furan\FormBuilder\Model\Config\Backend\SearchEndpoint;
use Furan\FormBuilder\Model\Http\PublicAddress;
use Furan\FormBuilder\Model\Http\SafeHttpClient;
use Furan\FormBuilder\Model\Http\UrlNormalizer;
use Furan\FormBuilder\Model\Map\MapRequestPolicy;
use Furan\FormBuilder\Test\Unit\Model\Http\FakeCurlSession;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

class SearchEndpointTest extends TestCase
{
    private function context(): Context
    {
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(ManagerInterface::class));

        return $context;
    }

    private function save(string $value): SearchEndpoint
    {
        $model = new SearchEndpoint(
            $this->context(),
            $this->createStub(Registry::class),
            $this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class),
            $this->createStub(\Magento\Framework\App\Cache\TypeListInterface::class),
            new UrlNormalizer(),
            new SafeHttpClient(new FakeCurlSession(), new PublicAddress(fn (string $host): array => [
                'nominatim.openstreetmap.org' => ['130.117.76.9'],
                'metadata.test' => ['169.254.169.254'],
                'rebind.test' => ['130.117.76.9', '127.0.0.1'],
            ][$host] ?? [])),
            new MapRequestPolicy($this->createStub(DeploymentConfig::class)),
            null,
            null,
            []
        );
        $model->setValue($value);
        $model->beforeSave();

        return $model;
    }

    public function testItRejectsASearchEndpointThatIsNotHttps(): void
    {
        $this->expectException(LocalizedException::class);
        $this->save('http://nominatim.test/search');
    }

    public function testItRejectsCredentialsInTheSearchEndpoint(): void
    {
        $this->expectException(LocalizedException::class);
        $this->save('https://user:pw@nominatim.test/search');
    }

    public function testItAcceptsAnHttpsSearchEndpoint(): void
    {
        $this->save('https://nominatim.openstreetmap.org/search');
        $this->save('');
        $this->addToAssertionCount(1);
    }

    public function testItRejectsASearchEndpointThatFailsTheUrlPolicyOnSave(): void
    {
        $urls = [
            'https://nominatim.openstreetmap.org:8443/search',
            'https://nominatim.openstreetmap.org/a/../search',
            'https://nominatim.openstreetmap.org#frag',
            'https://nominatim.openstreetmap.org\\@evil.test/',
            'https://0x7f.1/search',
            'https://metadata.test/search',
            'https://rebind.test/',
            'https://127.0.0.1/search',
            'https://[::1]/search',
            'https://10.1.2.3/',
            'https://unknown.test/',
        ];
        foreach ($urls as $url) {
            try {
                $this->save($url);
                $this->fail('Accepted ' . $url);
            } catch (LocalizedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testItStoresTheNormalisedSearchEndpoint(): void
    {
        $model = $this->save('  https://Nominatim.OpenStreetMap.org:443/search  ');
        $this->assertSame('https://nominatim.openstreetmap.org/search', $model->getValue());
    }
}
