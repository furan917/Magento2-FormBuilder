<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Directory;

use Furan\FormBuilder\Controller\Directory\Regions;
use Magento\Directory\Model\AllowedCountries;
use Magento\Directory\Model\ResourceModel\Region\Collection;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\DataObject;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class RegionsTest extends TestCase
{
    private Json&Stub $json;
    private AllowedCountries&MockObject $allowed;
    private CollectionFactory&Stub $collectionFactory;
    /** @var array<string, string> */
    private array $headers = [];

    protected function setUp(): void
    {
        $this->json = $this->configureJson($this->createStub(Json::class));
        $this->allowed = $this->createMock(AllowedCountries::class);
        $this->allowed->method('getAllowedCountries')->with('store', 3)->willReturn(['US' => 'US', 'GB' => 'GB']);
        $this->collectionFactory = $this->createStub(CollectionFactory::class);
    }

    private function controller(string $country): Regions
    {
        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($this->json);
        $request = $this->createMock(Http::class);
        $request->method('getParam')->with('country')->willReturn($country);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return new Regions($jsonFactory, $request, $storeManager, $this->allowed, $this->collectionFactory);
    }

    private function region(int $id, string $code, string $name): DataObject
    {
        return new DataObject(['region_id' => $id, 'code' => $code, 'name' => $name]);
    }

    /**
     * @template T of Json&Stub
     * @param T $json
     * @return T
     */
    private function configureJson(Json&Stub $json): Json&Stub
    {
        $json->method('setHeader')->willReturnCallback(
            function (string $name, string $value) {
                $this->headers[$name] = $value;
                return $this->json;
            }
        );
        return $json;
    }

    public function testItReturnsTheRegionsForAnAllowedCountryAsCacheableJson(): void
    {
        $json = $this->json = $this->configureJson($this->createMock(Json::class));
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())->method('addCountryFilter')->with('US')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            $this->region(12, 'CA', 'California'),
            $this->region(13, 'NY', 'New York'),
        ]));
        $this->collectionFactory->method('create')->willReturn($collection);
        $json->expects($this->once())->method('setData')->with([
            ['id' => '12', 'code' => 'CA', 'name' => 'California'],
            ['id' => '13', 'code' => 'NY', 'name' => 'New York'],
        ])->willReturnSelf();
        $json->expects($this->never())->method('setHttpResponseCode');

        $this->assertSame($this->json, $this->controller('US')->execute());
        $this->assertSame('public, max-age=86400, s-maxage=86400', $this->headers['Cache-Control']);
    }

    public function testItReturns404ForACountryNotAllowedInTheStore(): void
    {
        $collectionFactory = $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $json = $this->json = $this->configureJson($this->createMock(Json::class));
        $collectionFactory->expects($this->never())->method('create');
        $json->expects($this->once())->method('setHttpResponseCode')->with(404)->willReturnSelf();
        $json->expects($this->once())->method('setData')->with([])->willReturnSelf();

        $this->assertSame($this->json, $this->controller('FR')->execute());
        $this->assertSame('no-store, no-cache, must-revalidate, max-age=0, private', $this->headers['Cache-Control']);
    }
}
