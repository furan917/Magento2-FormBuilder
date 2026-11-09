<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Search;

use Furan\FormBuilder\Model\FormSearchResults;
use Furan\FormBuilder\Model\Search\KeysetCriteria;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\InputException;
use PHPUnit\Framework\TestCase;

class KeysetCriteriaTest extends TestCase
{
    private const FILTERABLE = ['form_id' => ['eq', 'in', 'gt'], 'title' => ['like']];

    /**
     * @param list<list<array{0: string, 1: mixed, 2?: string}>> $groups
     * @param list<array{0: string, 1?: string}> $sorts
     */
    private function criteria(array $groups = [], array $sorts = [], ?int $pageSize = null, ?int $page = null): SearchCriteria
    {
        $criteria = new SearchCriteria();
        $criteria->setFilterGroups(array_map(static fn (array $filters): FilterGroup => (new FilterGroup())->setFilters(
            array_map(static fn (array $f): Filter => (new Filter())->setField($f[0])->setValue($f[1])->setConditionType($f[2] ?? 'eq'), $filters)
        ), $groups));
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

    public function testItReturnsAllowedFiltersWithTheirConditions(): void
    {
        $filters = (new KeysetCriteria())->filters(
            $this->criteria([[['form_id', '7', 'gt']], [['title', '%news%', 'like']]]),
            self::FILTERABLE
        );

        $this->assertSame(
            [
                ['field' => 'form_id', 'condition' => 'gt', 'value' => '7'],
                ['field' => 'title', 'condition' => 'like', 'value' => '%news%'],
            ],
            $filters
        );
    }

    public function testItSplitsACommaSeparatedInFilterIntoAList(): void
    {
        $filters = (new KeysetCriteria())->filters($this->criteria([[['form_id', '3, 5', 'in']]]), self::FILTERABLE);

        $this->assertSame([['field' => 'form_id', 'condition' => 'in', 'value' => ['3', '5']]], $filters);
    }

    public function testItRefusesAFieldOutsideTheAllowlist(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('Filtering by "schema_json" is not supported.');
        (new KeysetCriteria())->filters($this->criteria([[['schema_json', 'x']]]), self::FILTERABLE);
    }

    public function testItRefusesAConditionTheFieldDoesNotAllow(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('The "like" condition is not supported for "form_id".');
        (new KeysetCriteria())->filters($this->criteria([[['form_id', '%1%', 'like']]]), self::FILTERABLE);
    }

    public function testItRefusesOrFiltersInOneGroup(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('Put each filter in its own filter group; OR filters are not supported.');
        (new KeysetCriteria())->filters($this->criteria([[['form_id', '1'], ['form_id', '2']]]), self::FILTERABLE);
    }

    public function testItAppendsThePrimaryKeyInTheDirectionOfTheLastSort(): void
    {
        $keyset = new KeysetCriteria();

        $this->assertSame(['form_id' => 'ASC'], $keyset->sortOrders($this->criteria(), ['title'], 'form_id'));
        $this->assertSame(
            ['title' => 'DESC', 'form_id' => 'DESC'],
            $keyset->sortOrders($this->criteria([], [['title', 'DESC']]), ['title'], 'form_id')
        );
        $this->assertSame(
            ['form_id' => 'DESC'],
            $keyset->sortOrders($this->criteria([], [['form_id', 'DESC']]), ['title'], 'form_id')
        );
    }

    public function testItKeepsThePrimaryKeyLastWhenItIsSortedFirst(): void
    {
        $this->assertSame(
            ['form_id' => 'ASC'],
            (new KeysetCriteria())->sortOrders($this->criteria([], [['form_id'], ['title']]), ['title'], 'form_id')
        );
    }

    public function testItRefusesASortFieldOutsideTheAllowlist(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('Sorting by "summary" is not supported.');
        (new KeysetCriteria())->sortOrders($this->criteria([], [['summary']]), ['title'], 'form_id');
    }

    public function testItDefaultsAndCapsThePageSize(): void
    {
        $keyset = new KeysetCriteria();

        $this->assertSame(100, $keyset->pageSize($this->criteria(), 'form_id'));
        $this->assertSame(100, $keyset->pageSize($this->criteria([], [], 0), 'form_id'));
        $this->assertSame(20, $keyset->pageSize($this->criteria([], [], 20, 1), 'form_id'));
        $this->assertSame(500, $keyset->pageSize($this->criteria([], [], 10000), 'form_id'));
    }

    public function testItRefusesAPageAfterTheFirst(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage(
            'currentPage above 1 is not supported. Page with a "form_id" "gt" filter, sorted by form_id.'
        );
        (new KeysetCriteria())->pageSize($this->criteria([], [], 20, 2), 'form_id');
    }

    public function testItFillsOnePageAndSignalsMoreFromTheExtraRow(): void
    {
        $criteria = $this->criteria();
        $rows = [new DataObject(['id' => 1]), new DataObject(['id' => 2]), new DataObject(['id' => 3])];

        $results = (new KeysetCriteria())->fill(new FormSearchResults(), $criteria, $rows, 2);

        $this->assertSame([$rows[0], $rows[1]], $results->getItems());
        $this->assertSame(2, $results->getTotalCount());
        $this->assertTrue($results->getHasMore());
        $this->assertSame($criteria, $results->getSearchCriteria());

        $last = (new KeysetCriteria())->fill(new FormSearchResults(), $criteria, [$rows[0]], 2);
        $this->assertSame(1, $last->getTotalCount());
        $this->assertFalse($last->getHasMore());
    }
}
