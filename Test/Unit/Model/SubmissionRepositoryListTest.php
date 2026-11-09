<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model;

use Furan\FormBuilder\Api\Data\SubmissionSearchResultsInterfaceFactory;
use Furan\FormBuilder\Model\ResourceModel\Submission as SubmissionResource;
use Furan\FormBuilder\Model\ResourceModel\Submission\Collection;
use Furan\FormBuilder\Model\ResourceModel\Submission\CollectionFactory;
use Furan\FormBuilder\Model\Search\KeysetCriteria;
use Furan\FormBuilder\Model\Stats\Journal;
use Furan\FormBuilder\Model\Submission;
use Furan\FormBuilder\Model\SubmissionFactory;
use Furan\FormBuilder\Model\SubmissionRepository;
use Furan\FormBuilder\Model\SubmissionSearchResults;
use Furan\FormBuilder\Model\Webhook\SubmissionDeliveryCleaner;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\Exception\InputException;
use Furan\FormBuilder\Test\Unit\Helper\ModelFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SubmissionRepositoryListTest extends TestCase
{
    private Collection&MockObject $collection;
    private Select $select;
    /** @var list<array{0: string, 1: mixed}> */
    private array $filters = [];

    /** @param list<Submission> $items */
    private function repository(array $items = []): SubmissionRepository
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
        $resultsFactory = $this->createStub(SubmissionSearchResultsInterfaceFactory::class);
        $resultsFactory->method('create')->willReturnCallback(static fn () => new SubmissionSearchResults());

        return new SubmissionRepository(
            $this->createStub(SubmissionResource::class),
            $this->createStub(SubmissionFactory::class),
            $this->createStub(Journal::class),
            $this->createStub(SubmissionDeliveryCleaner::class),
            $collectionFactory,
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

    private function submission(int $id): Submission
    {
        $submission = ModelFixtures::model(Submission::class);
        $submission->setIdFieldName('submission_id');
        return $submission->setId($id);
    }

    public function testItFiltersOnIndexedColumnsWithBoundConditions(): void
    {
        $repository = $this->repository();

        $repository->getList($this->criteria([
            ['form_id', '3'],
            ['status', '1'],
            ['is_read', '0'],
            ['created_at', '2026-01-01 00:00:00', 'gteq'],
        ]));

        $this->assertSame(
            [
                ['main_table.form_id', ['eq' => '3']],
                ['main_table.status', ['eq' => '1']],
                ['main_table.is_read', ['eq' => '0']],
                ['main_table.created_at', ['gteq' => '2026-01-01 00:00:00']],
            ],
            $this->filters
        );
    }

    /** @return array<string, array{0: list<array{0: string, 1: mixed, 2?: string}>}> */
    public static function refusedFilters(): array
    {
        return [
            'payload' => [[['payload_json', '%x%', 'like']]],
            'email hash' => [[['email_hash', 'abc']]],
            'summary' => [[['summary', 'x']]],
            'like on an indexed column' => [[['form_id', '3%', 'like']]],
            'neq scans' => [[['status', '1', 'neq']]],
            'in on a secondary index sorts' => [[['status', '0,1', 'in']]],
            'in on the form id sorts' => [[['form_id', '3,4', 'in']]],
            'updated_at alone' => [[['updated_at', '2026-01-01', 'gt']]],
            'updated_at with only a range' => [[['updated_at', '2026-01-01', 'gt'], ['submission_id', '9', 'gt']]],
        ];
    }

    /** @param list<array{0: string, 1: mixed, 2?: string}> $filters */
    #[DataProvider('refusedFilters')]
    public function testItRefusesFiltersThatCannotUseAnIndex(array $filters): void
    {
        $repository = $this->repository();

        $this->expectException(InputException::class);
        $repository->getList($this->criteria($filters));
    }

    public function testItAllowsAnUpdatedAtFilterNextToAnIndexedEquality(): void
    {
        $repository = $this->repository();

        $repository->getList($this->criteria([['updated_at', '2026-01-01', 'gt'], ['customer_id', '5']]));

        $this->assertCount(2, $this->filters);
    }

    public function testItSortsBySubmissionIdOnly(): void
    {
        $repository = $this->repository();

        $this->expectException(InputException::class);
        $this->expectExceptionMessage('Sorting by "created_at" is not supported.');
        $repository->getList($this->criteria([], [['created_at', 'DESC']]));
    }

    public function testItRefusesASecondPage(): void
    {
        $repository = $this->repository();

        $this->expectException(InputException::class);
        $this->expectExceptionMessage('Page with a "submission_id" "gt" filter, sorted by submission_id.');
        $repository->getList($this->criteria([], [], 100, 3));
    }

    public function testItPagesByKeysetWithoutOffset(): void
    {
        $repository = $this->repository();

        $repository->getList($this->criteria([['submission_id', '1200', 'lt']], [['submission_id', 'DESC']], 50));

        $this->assertSame([['main_table.submission_id', 'DESC']], $this->select->getPart(Select::ORDER));
        $this->assertSame(51, $this->select->getPart(Select::LIMIT_COUNT));
        $this->assertEmpty($this->select->getPart(Select::LIMIT_OFFSET));
    }

    public function testItDefaultsAndCapsThePageSize(): void
    {
        $this->repository()->getList($this->criteria());
        $this->assertSame(101, $this->select->getPart(Select::LIMIT_COUNT));

        $this->repository()->getList($this->criteria([], [], 100000));
        $this->assertSame(501, $this->select->getPart(Select::LIMIT_COUNT));
    }

    public function testItCountsOnlyTheReturnedPageAndSignalsMore(): void
    {
        $rows = [$this->submission(7), $this->submission(8)];
        $results = $this->repository($rows)->getList($this->criteria([], [], 1));

        $this->assertSame([$rows[0]], $results->getItems());
        $this->assertSame(1, $results->getTotalCount());
        $this->assertTrue($results->getHasMore());
    }
}
