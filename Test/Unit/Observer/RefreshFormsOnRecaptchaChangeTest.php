<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Observer;

use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Observer\RefreshFormsOnRecaptchaChange;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\CacheContextFactory;
use PHPUnit\Framework\TestCase;

class RefreshFormsOnRecaptchaChangeTest extends TestCase
{
    public function testItCleansEveryFormCacheTagWhenTheRecaptchaStorefrontSectionIsSaved(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('from')->with('tbl_form', ['form_id'])->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->with($select)->willReturn(['3', '8']);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->with('furan_formbuilder_form')->willReturn('tbl_form');
        $contexts = $this->createStub(CacheContextFactory::class);
        $contexts->method('create')->willReturnCallback(static fn () => new CacheContext());
        $events = $this->createMock(ManagerInterface::class);
        $events->expects(self::once())->method('dispatch')->with(
            'clean_cache_by_tags',
            self::callback(static fn(array $data): bool => $data['object']->getIdentities() === ['furan_form_3', 'furan_form_8']
                && Form::CACHE_TAG === 'furan_form')
        );

        (new RefreshFormsOnRecaptchaChange($resource, $contexts, $events))->execute(new Observer());
    }

    public function testItDoesNotDispatchWhenThereAreNoForms(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->createStub(Select::class));
        $connection->method('fetchCol')->willReturn([]);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $events = $this->createMock(ManagerInterface::class);
        $events->expects(self::never())->method('dispatch');

        (new RefreshFormsOnRecaptchaChange(
            $resource,
            $this->createStub(CacheContextFactory::class),
            $events
        ))->execute(new Observer());
    }
}
