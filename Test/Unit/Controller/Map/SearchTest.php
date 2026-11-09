<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Map;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Controller\Map\Search;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Map\GeocoderBusyException;
use Furan\FormBuilder\Model\Map\NominatimClient;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Furan\FormBuilder\Model\Http\ClientIp;
use Furan\FormBuilder\Model\Spam\RateLimiter;
use Furan\FormBuilder\Test\Unit\Helper\MemoryCounter;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SearchTest extends TestCase
{
    private ?MemoryCounter $counter = null;
    private const NO_STORE = 'no-store, no-cache, must-revalidate, max-age=0, private';

    private Config&Stub $config;
    private FormRepositoryInterface&Stub $repository;
    private NominatimClient&Stub $client;
    private Json&Stub $json;
    private array $params = ['form_id' => '12', 'q' => 'Berlin'];
    private array $headers = [];
    private ?int $code = null;
    private mixed $data = null;
    private float $now = 1000.0;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('isMapSearchEnabled')->willReturn(true);
        $this->config->method('getMapProvider')->willReturn('osm');
        $this->repository = $this->createMock(FormRepositoryInterface::class);
        $this->repository->method('getActiveById')->with(12, 3)->willReturn($this->form('map'));
        $this->client = $this->createStub(NominatimClient::class);
        $this->json = $this->createStub(Json::class);
        $this->json->method('setHeader')->willReturnCallback(function (string $n, string $v) {
            $this->headers[$n] = $v;
            return $this->json;
        });
        $this->json->method('setHttpResponseCode')->willReturnCallback(function (int $c) {
            $this->code = $c;
            return $this->json;
        });
        $this->json->method('setData')->willReturnCallback(function ($d) {
            $this->data = $d;
            return $this->json;
        });
    }

    private function form(string $type): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getSchema')->willReturn(['pages' => [['items' => [
            ['kind' => 'field', 'type' => 'text'],
            ['kind' => 'section', 'items' => [['kind' => 'field', 'type' => $type]]],
        ]]]]);
        return $form;
    }

    private function controller(): Search
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(fn(string $k) => $this->params[$k] ?? null);
        $storeObj = $this->createStub(StoreInterface::class);
        $storeObj->method('getId')->willReturn(3);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($storeObj);
        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($this->json);
        $ip = $this->createStub(ClientIp::class);
        $ip->method('bucket')->willReturn('203.0.113.9');
        $this->counter ??= new MemoryCounter(fn (): int => (int)$this->now);
        $limiter = new RateLimiter($this->counter, $ip, $this->config, fn (): int => (int)$this->now);

        return new Search(
            $jsonFactory,
            $request,
            $this->repository,
            $storeManager,
            $this->config,
            $this->client,
            $limiter
        );
    }

    public function testItReturns404WhenSearchIsDisabled(): void
    {
        $client = $this->client = $this->createMock(NominatimClient::class);
        $config = $this->createStub(Config::class);
        $config->method('isMapSearchEnabled')->willReturn(false);
        $config->method('getMapProvider')->willReturn('osm');
        $this->config = $config;
        $client->expects($this->never())->method('search');

        $this->controller()->execute();

        $this->assertSame(404, $this->code);
        $this->assertSame(self::NO_STORE, $this->headers['Cache-Control']);
    }

    public function testItReturns404WhenTheProviderIsNotOsm(): void
    {
        $client = $this->client = $this->createMock(NominatimClient::class);
        $config = $this->createStub(Config::class);
        $config->method('isMapSearchEnabled')->willReturn(true);
        $config->method('getMapProvider')->willReturn('google');
        $this->config = $config;
        $client->expects($this->never())->method('search');

        $this->controller()->execute();

        $this->assertSame(404, $this->code);
    }

    public function testItReturns404UnlessTheFormIdNamesAnActiveFormInThisStoreWithAMapField(): void
    {
        $client = $this->client = $this->createMock(NominatimClient::class);
        $client->expects($this->never())->method('search');

        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getActiveById')->willThrowException(new NoSuchEntityException());
        $this->repository = $repository;
        $this->controller()->execute();
        $this->assertSame(404, $this->code);

        $repository = $this->createStub(FormRepositoryInterface::class);
        $repository->method('getActiveById')->willReturn($this->form('text'));
        $this->repository = $repository;
        $this->code = null;
        $this->controller()->execute();
        $this->assertSame(404, $this->code);
        $this->assertSame(self::NO_STORE, $this->headers['Cache-Control']);
    }

    public function testItRejectsQueriesShorterThan3OrLongerThan200Characters(): void
    {
        $client = $this->client = $this->createMock(NominatimClient::class);
        $client->expects($this->never())->method('search');
        foreach (['ab', "  a\x00b ", str_repeat('x', 201)] as $query) {
            $this->params['q'] = $query;
            $this->code = null;
            $this->controller()->execute();
            $this->assertSame(400, $this->code);
            $this->assertSame(self::NO_STORE, $this->headers['Cache-Control']);
        }
    }

    public function testItReturnsResultsWithPrivateCacheHeaders(): void
    {
        $client = $this->client = $this->createMock(NominatimClient::class);
        $results = [['label' => 'Berlin', 'lat' => '52.520008', 'lon' => '13.404954']];
        $client->expects($this->once())->method('search')->with('Berlin', 3)->willReturn($results);

        $this->controller()->execute();

        $this->assertNull($this->code);
        $this->assertSame($results, $this->data);
        $this->assertSame('private, max-age=300', $this->headers['Cache-Control']);
    }

    public function testItReportsUpstreamFailureAsNotCacheable(): void
    {
        $this->client->method('search')->willReturn(null);

        $this->controller()->execute();

        $this->assertSame(502, $this->code);
        $this->assertSame(self::NO_STORE, $this->headers['Cache-Control']);
    }

    public function testItReturnsABusyResponseWhileTheGeocoderIsThrottled(): void
    {
        $this->client->method('search')->willThrowException(new GeocoderBusyException());

        $this->controller()->execute();

        $this->assertSame(429, $this->code);
        $this->assertSame(['busy' => true], $this->data);
        $this->assertSame(self::NO_STORE, $this->headers['Cache-Control']);
    }

    public function testItRejectsQueriesAboveThePerIpLimit(): void
    {
        $this->client->method('search')->willReturn([]);
        for ($i = 0; $i < 20; $i++) {
            $this->now += 2;
            $this->code = null;
            $this->controller()->execute();
            $this->assertNull($this->code, 'request ' . $i);
        }

        $this->now += 2;
        $this->controller()->execute();

        $this->assertSame(429, $this->code);
        $this->assertSame(['busy' => false], $this->data);
        $this->assertSame(self::NO_STORE, $this->headers['Cache-Control']);
    }

    public function testItMarksBusyAndErrorResponsesAsNotCacheable(): void
    {
        $this->params['q'] = '';
        $this->controller()->execute();
        $this->assertSame(self::NO_STORE, $this->headers['Cache-Control']);
        $this->assertSame('no-cache', $this->headers['Pragma']);
        $this->assertSame('noindex', $this->headers['X-Robots-Tag']);
    }
}
