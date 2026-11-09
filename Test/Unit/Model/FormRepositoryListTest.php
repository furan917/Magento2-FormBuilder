<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model;

use Furan\FormBuilder\Api\Data\FormSearchResultsInterfaceFactory;
use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Model\FormFactory;
use Furan\FormBuilder\Model\FormRepository;
use Furan\FormBuilder\Model\FormSearchResults;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Furan\FormBuilder\Model\ResourceModel\Form\Collection;
use Furan\FormBuilder\Model\ResourceModel\Form\CollectionFactory;
use Furan\FormBuilder\Model\Schema\SchemaMigrator;
use Furan\FormBuilder\Model\Search\KeysetCriteria;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\Exception\InputException;
use Furan\FormBuilder\Test\Unit\Helper\ModelFixtures;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FormRepositoryListTest extends TestCase
{
    private Collection&MockObject $collection;
    private Select $select;
    /** @var list<array{0: string, 1: mixed}> */
    private array $filters = [];

    /** @param list<Form> $items */
    private function repository(array $items = [], ?FormResource $resource = null): FormRepository
    {
        $this->select = new Select($this->createStub(Mysql::class), $this->createStub(SelectRenderer::class));
        $this->collection = $this->createMock(Collection::class);
        $this->collection->expects($this->never())->method('setPageSize');
        $this->collection->expects($this->never())->method('setCurPage');
        $this->collection->expects($this->never())->method('getSize');
        $this->collection->method('getSelect')->willReturn($this->select);
        $this->collection->method('getItems')->willReturn($items);
        $this->collection->method('addFieldToFilter')->willReturnCallback(function ($field, $condition) {
            $this->filters[] = [$field, $condition];
            return $this->collection;
        });
        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($this->collection);
        $resultsFactory = $this->createStub(FormSearchResultsInterfaceFactory::class);
        $resultsFactory->method('create')->willReturnCallback(static fn () => new FormSearchResults());
        return new FormRepository(
            $resource ?? $this->createStub(FormResource::class),
            $this->createStub(FormFactory::class),
            $collectionFactory,
            new SchemaMigrator($this->createStub(LoggerInterface::class)),
            $this->createStub(LoggerInterface::class),
            $resultsFactory,
            new KeysetCriteria()
        );
    }

    /**
     * @param list<array{0: string, 1: mixed, 2?: string}> $filters
     * @param list<array{0: string, 1?: string}> $sorts
     */
    private function criteria(array $filters = [], array $sorts = [], ?int $pageSize = null, ?int $page = null): SearchCriteria
    {
        $criteria = new SearchCriteria();
        $criteria->setFilterGroups(array_map(
            static fn (array $f): FilterGroup => (new FilterGroup())->setFilters([
                (new Filter())->setField($f[0])->setValue($f[1])->setConditionType($f[2] ?? 'eq'),
            ]),
            $filters
        ));
        $criteria->setSortOrders(array_map(
            static fn (array $s): SortOrder => new SortOrder(['field' => $s[0], 'direction' => $s[1] ?? 'ASC']),
            $sorts
        ));
        if ($pageSize !== null) {
            $criteria->setPageSize($pageSize);
        }
        if ($page !== null) {
            $criteria->setCurrentPage($page);
        }
        return $criteria;
    }

    private function form(int $id): Form
    {
        $form = ModelFixtures::model(Form::class);
        $form->setIdFieldName('form_id');
        return $form->setId($id);
    }

    public function testItFiltersOnTheMainTableWithBoundConditions(): void
    {
        $repository = $this->repository();
        $this->collection->expects($this->never())->method('addStoreFilter');

        $repository->getList($this->criteria([['is_active', '1'], ['identifier', 'contact%', 'like']]));

        $this->assertSame(
            [['main_table.is_active', ['eq' => '1']], ['main_table.identifier', ['like' => 'contact%']]],
            $this->filters
        );
    }

    public function testItFiltersByStoreThroughTheCollectionStoreFilter(): void
    {
        $repository = $this->repository();
        $this->collection->expects($this->once())->method('addStoreFilter')->with(3);

        $repository->getList($this->criteria([['store_id', '3']]));

        $this->assertSame([], $this->filters);
    }

    public function testItRefusesAStoreConditionOtherThanEquality(): void
    {
        $repository = $this->repository();

        $this->expectException(InputException::class);
        $repository->getList($this->criteria([['store_id', '1,2', 'in']]));
    }

    public function testItRefusesAFieldOutsideTheAllowlist(): void
    {
        $repository = $this->repository();

        $this->expectException(InputException::class);
        $this->expectExceptionMessage('Filtering by "settings_json" is not supported.');
        $repository->getList($this->criteria([['settings_json', '%secret%', 'like']]));
    }

    public function testItRefusesASortOutsideTheAllowlist(): void
    {
        $repository = $this->repository();

        $this->expectException(InputException::class);
        $repository->getList($this->criteria([], [['schema_json']]));
    }

    public function testItRefusesASecondPage(): void
    {
        $repository = $this->repository();

        $this->expectException(InputException::class);
        $this->expectExceptionMessage('Page with a "form_id" "gt" filter, sorted by form_id.');
        $repository->getList($this->criteria([], [], 50, 2));
    }

    public function testItOrdersByTheSortThenTheIdAndFetchesOneRowMoreWithoutOffset(): void
    {
        $repository = $this->repository();

        $repository->getList($this->criteria([['form_id', '40', 'gt']], [['title', 'DESC']], 20));

        $this->assertSame([['main_table.title', 'DESC'], ['main_table.form_id', 'DESC']], $this->select->getPart(Select::ORDER));
        $this->assertSame(21, $this->select->getPart(Select::LIMIT_COUNT));
        $this->assertEmpty($this->select->getPart(Select::LIMIT_OFFSET));
        $this->assertSame([['main_table.form_id', ['gt' => '40']]], $this->filters);
    }

    public function testItCapsThePageSize(): void
    {
        $repository = $this->repository();

        $repository->getList($this->criteria([], [], 5000));

        $this->assertSame(501, $this->select->getPart(Select::LIMIT_COUNT));
    }

    public function testItReturnsThePageWithTranslationsAndAHasMoreSignal(): void
    {
        $forms = [$this->form(4), $this->form(9), $this->form(12)];
        $resource = $this->createMock(FormResource::class);
        $repository = $this->repository($forms, $resource);
        $resource->expects($this->once())->method('lookupTranslationsFor')->with([4, 9])
            ->willReturn([9 => [2 => ['title' => 'Kontakt']]]);

        $results = $repository->getList($this->criteria([], [], 2));

        $this->assertSame([$forms[0], $forms[1]], $results->getItems());
        $this->assertSame(2, $results->getTotalCount());
        $this->assertTrue($results->getHasMore());
        $this->assertSame([], $forms[0]->getTranslations());
        $this->assertSame([2 => ['title' => 'Kontakt']], $forms[1]->getTranslations());
    }

    public function testItSkipsTheTranslationQueryForAnEmptyPage(): void
    {
        $resource = $this->createMock(FormResource::class);
        $repository = $this->repository([], $resource);
        $resource->expects($this->never())->method('lookupTranslationsFor');

        $results = $repository->getList($this->criteria());

        $this->assertSame([], $results->getItems());
        $this->assertFalse($results->getHasMore());
    }
}
