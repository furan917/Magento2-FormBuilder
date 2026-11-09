<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model;

use Furan\FormBuilder\Api\Data\FormSearchResultsInterfaceFactory;
use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Model\FormFactory;
use Furan\FormBuilder\Model\FormRepository;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Furan\FormBuilder\Model\ResourceModel\Form\Collection;
use Furan\FormBuilder\Model\ResourceModel\Form\CollectionFactory;
use Furan\FormBuilder\Model\Schema\SchemaMigrator;
use Furan\FormBuilder\Model\Search\KeysetCriteria;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FormRepositoryTest extends TestCase
{
    private FormResource&Stub $resource;
    private FormFactory&Stub $formFactory;
    private Collection&MockObject $collection;
    private FormRepository $repository;
    private LoggerInterface&Stub $logger;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?LoggerInterface $logger = null, ?FormResource $resource = null): void
    {
        $this->logger = $logger ?? $this->createStub(LoggerInterface::class);
        $this->resource = $resource ?? $this->createStub(FormResource::class);
        $this->formFactory = $this->createStub(FormFactory::class);
        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('addFieldToFilter')->willReturnSelf();
        $this->collection->method('addFieldToSelect')->willReturnSelf();
        $this->collection->method('addStoreFilter')->willReturnSelf();
        $this->collection->expects($this->never())->method('setPageSize');
        $select = $this->createMock(\Magento\Framework\DB\Select::class);
        $select->method('limit')->with(1)->willReturnSelf();
        $this->collection->method('getSelect')->willReturn($select);
        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($this->collection);

        $this->repository = new FormRepository(
            $this->resource,
            $this->formFactory,
            $collectionFactory,
            new SchemaMigrator($this->createStub(LoggerInterface::class)),
            $this->logger,
            $this->createStub(FormSearchResultsInterfaceFactory::class),
            new KeysetCriteria()
        );
    }

    private function formWithId(?int $id): Form&Stub
    {
        $form = $this->createStub(Form::class);
        $form->method('getId')->willReturn($id);
        return $form;
    }

    public function testItReturnsAnActiveFormByIdentifierForAMatchingStore(): void
    {
        $resource = $this->createMock(FormResource::class);
        $this->fixture(resource: $resource);
        $this->collection->method('getFirstItem')->willReturn($this->formWithId(4));
        $form = $this->formWithId(4);
        $this->formFactory->method('create')->willReturn($form);
        $resource->expects($this->once())->method('load')->with($form, 4);

        $this->assertSame($form, $this->repository->getActiveByIdentifier('contact', 2));
    }

    public function testItLooksUpOnlyTheIdBeforeLoadingTheFormOnce(): void
    {
        $resource = $this->createMock(FormResource::class);
        $this->fixture(resource: $resource);
        $this->collection->method('getFirstItem')->willReturn($this->formWithId(4));
        $this->formFactory->method('create')->willReturn($this->formWithId(4));
        $resource->expects($this->once())->method('load');
        $selected = [];
        $collection = $this->collection;
        $collection->method('addFieldToSelect')->willReturnCallback(function ($field) use (&$selected, $collection) {
            $selected[] = $field;
            return $collection;
        });

        $this->repository->getActiveById(4, 2);
        $this->assertSame(['form_id'], $selected);
    }

    public function testItReturnsAnActiveFormByIdentifierAssignedToAllStores(): void
    {
        $this->collection->method('getFirstItem')->willReturn($this->formWithId(4));
        $this->formFactory->method('create')->willReturn($this->formWithId(4));
        $this->collection->expects($this->once())->method('addStoreFilter')->with(2);

        $this->repository->getActiveByIdentifier('contact', 2);
    }

    public function testItThrowsWhenTheIdentifierBelongsToAnInactiveForm(): void
    {
        $this->collection->method('getFirstItem')->willReturn($this->formWithId(null));
        $filters = [];
        $collection = $this->collection;
        $collection->expects($this->atLeastOnce())->method('addFieldToFilter')
            ->willReturnCallback(function (string $field, $value) use (&$filters, $collection) {
                $filters[$field] = $value;
                return $collection;
            });

        try {
            $this->repository->getActiveByIdentifier('contact', 2);
            $this->fail('Expected NoSuchEntityException');
        } catch (NoSuchEntityException) {
            $this->assertSame(['main_table.identifier' => 'contact', 'main_table.is_active' => 1], $filters);
        }
    }

    public function testItReturnsAnActiveFormByIdForAMatchingStore(): void
    {
        $this->collection->method('getFirstItem')->willReturn($this->formWithId(8));
        $form = $this->formWithId(8);
        $this->formFactory->method('create')->willReturn($form);

        $this->assertSame($form, $this->repository->getActiveById(8, 1));
    }

    public function testItQualifiesTheFormIdFilterBecauseTheStoreJoinAlsoHasFormId(): void
    {
        $this->collection->method('getFirstItem')->willReturn($this->formWithId(8));
        $this->formFactory->method('create')->willReturn($this->formWithId(8));
        $fields = [];
        $collection = $this->collection;
        $collection->method('addFieldToFilter')
            ->willReturnCallback(function (string $field) use (&$fields, $collection) {
                $fields[] = $field;
                return $collection;
            });

        $this->repository->getActiveById(8, 1);
        $this->assertSame(['main_table.form_id', 'main_table.is_active'], $fields);
    }

    public function testItThrowsNoSuchEntityWhenTheFormIdDoesNotExist(): void
    {
        $this->formFactory->method('create')->willReturn($this->formWithId(null));

        $this->expectException(NoSuchEntityException::class);
        $this->repository->getById(99);
    }

    public function testItWrapsSaveFailuresInACouldNotSaveException(): void
    {
        $this->resource->method('save')->willThrowException(new \RuntimeException('db down'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->save($this->formWithId(1));
    }

    public function testItRendersNothingAndLogsWhenAStoredSchemaIsNewerThanTheModuleSupports(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->fixture(logger: $logger);
        $this->collection->method('getFirstItem')->willReturn($this->formWithId(4));
        $form = $this->formWithId(4);
        $form->method('getSchema')->willReturn(['version' => 99, 'pages' => [['title' => 'secret title']]]);
        $this->formFactory->method('create')->willReturn($form);
        $logger->expects($this->once())->method('warning')->with(
            $this->logicalAnd($this->stringContains('4'), $this->stringContains('99'), $this->logicalNot($this->stringContains('secret')))
        );

        $this->expectException(NoSuchEntityException::class);
        $this->repository->getActiveById(4, 1);
    }

    public function testItStillLoadsAnUnsupportedSchemaByIdForTheAdmin(): void
    {
        $form = $this->formWithId(4);
        $form->method('getSchema')->willReturn(['version' => 99]);
        $this->formFactory->method('create')->willReturn($form);

        $this->assertSame($form, $this->repository->getById(4));
    }
}
