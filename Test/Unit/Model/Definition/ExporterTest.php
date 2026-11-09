<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Definition;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Model\Definition\Exporter;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ExporterTest extends TestCase
{
    public function testItExportsAndImportsStoreViewsByCode(): void
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getIdentifier')->willReturn('contact');
        $form->method('getTitle')->willReturn('Contact');
        $form->method('isActive')->willReturn(true);
        $form->method('getStoreIds')->willReturn([0, 2]);
        $form->method('getSchema')->willReturn(['version' => 1, 'pages' => []]);
        $form->method('getSettings')->willReturn(['submit_label' => 'Go']);

        $this->assertSame([
            'identifier' => 'contact',
            'title' => 'Contact',
            'is_active' => true,
            'store_codes' => ['admin', 'fr'],
            'schema' => ['version' => 1, 'pages' => []],
            'settings' => ['submit_label' => 'Go'],
            'translations' => [],
        ], (new Exporter($this->storeManager()))->export($form));
    }

    private function storeManager(): StoreManagerInterface
    {
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturnCallback(function (int $id): StoreInterface {
            if ($id !== 2) {
                throw new NoSuchEntityException();
            }
            $store = $this->createStub(StoreInterface::class);
            $store->method('getCode')->willReturn('fr');
            return $store;
        });

        return $stores;
    }
}
