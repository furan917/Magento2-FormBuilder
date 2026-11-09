<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Model\Submission\GridSelection;
use Magento\Framework\Api\Filter as ApiFilter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProviderInterface;
use Magento\Framework\View\Element\UiComponentInterface;
use Magento\Ui\Component\MassAction\Filter;
use PHPUnit\Framework\TestCase;

class GridSelectionTest extends TestCase
{
    /** @var array<int, array{string, mixed}> calls on the cloned select */
    private array $calls = [];
    /** @var array<int, int> cursor of each page query */
    private array $cursors = [];
    /** @var ApiFilter[] */
    private array $filters = [];
    private int $prepared = 0;
    private array $sql = [];
    private array $where = [];

    /** @param array<int, int[]> $pages */
    private function selection(array $pages): GridSelection
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchCol')->willReturnCallback(function (string $sql) use (&$pages): array {
            $this->sql[] = $sql;
            return array_shift($pages) ?? [];
        });
        $select = $this->createStub(Select::class);
        foreach (['reset', 'columns', 'order', 'limit', 'where'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($method, $select) {
                $this->calls[] = $method === 'where' ? [$method, $args[0], $args[1] ?? null] : [$method, $args[0]];
                if ($method === 'where' && str_contains((string)$args[0], 'submission_id >')) {
                    $this->cursors[] = $args[1];
                }
                return $select;
            });
        }
        $select->method('getConnection')->willReturn($connection);
        $select->method('getPart')->willReturnCallback(fn (string $part) => match ($part) {
            Select::FROM => ['main_table' => []],
            Select::WHERE => $this->where,
            default => [],
        });
        $select->method('__toString')->willReturn('SELECT `main_table`.`submission_id` FROM `t` AS `main_table` WHERE x');
        $collection = $this->createMock(AbstractDb::class);
        $collection->method('getSelect')->willReturn($select);
        $collection->expects($this->never())->method('getAllIds');
        $collection->expects($this->never())->method('getItems');
        $provider = $this->createStub(DataProviderInterface::class);
        $provider->method('getSearchResult')->willReturn($collection);
        $provider->method('getPrimaryFieldName')->willReturn('submission_id');
        $provider->method('addFilter')->willReturnCallback(function (ApiFilter $added): void {
            $this->filters[] = $added;
        });
        $context = $this->createStub(ContextInterface::class);
        $context->method('getDataProvider')->willReturn($provider);
        $component = $this->createStub(UiComponentInterface::class);
        $component->method('getContext')->willReturn($context);
        $filter = $this->createMock(Filter::class);
        $filter->method('getComponent')->willReturn($component);
        $filter->expects($this->never())->method('getCollection');
        $filter->expects($this->never())->method('applySelectionOnTargetProvider');
        $filter->method('prepareComponent')->willReturnCallback(function (): void {
            $this->prepared++;
        });

        $keyset = $this->createStub(\Furan\FormBuilder\Model\ResourceModel\Submission\KeysetIndex::class);
        $keyset->method('forForm')->willReturn(' FORCE INDEX (`F`)');
        $keyset->method('forPrimary')->willReturn(' FORCE INDEX (PRIMARY)');

        return new GridSelection($filter, $this->filterBuilder(), $keyset);
    }

    private function filterBuilder(): FilterBuilder
    {
        $pending = new ApiFilter();
        $builder = $this->createStub(FilterBuilder::class);
        $builder->method('setField')->willReturnCallback(function ($v) use ($builder, &$pending) {
            $pending->setField($v);
            return $builder;
        });
        $builder->method('setValue')->willReturnCallback(function ($v) use ($builder, &$pending) {
            $pending->setValue($v);
            return $builder;
        });
        $builder->method('setConditionType')->willReturnCallback(function ($v) use ($builder, &$pending) {
            $pending->setConditionType($v);
            return $builder;
        });
        $builder->method('create')->willReturnCallback(function () use (&$pending) {
            $created = $pending;
            $pending = new ApiFilter();
            return $created;
        });

        return $builder;
    }

    public function testItWalksASelectAllByKeysetWithoutLoadingEveryIdOrRow(): void
    {
        $batches = iterator_to_array($this->selection([[1, 2, 3], [7, 9, 12], [15]])->batches(3, null, 'false'), false);

        $this->assertSame([[1, 2, 3], [7, 9, 12], [15]], $batches);
        $this->assertSame([0, 3, 12], $this->cursors);
        $this->assertContains(['columns', 'main_table.submission_id'], $this->calls);
        $this->assertContains(['order', 'main_table.submission_id ASC'], $this->calls);
        $this->assertContains(['limit', 3], $this->calls);
    }

    public function testItAddsExtraConditionsSoRepeatedRunsSkipRowsAlreadyDone(): void
    {
        iterator_to_array($this->selection([[1]])->batches(10, null, 'false', ['main_table.status <> ?' => 2]));

        $this->assertContains(['where', 'main_table.status <> ?', 2], $this->calls);
    }

    public function testItRequiresASelection(): void
    {
        $this->expectException(LocalizedException::class);
        iterator_to_array($this->selection([])->batches(10, null, null));
    }

    public function testItAcceptsExplicitlySelectedIds(): void
    {
        $this->assertSame([[4, 5]], iterator_to_array($this->selection([[4, 5]])->batches(10, ['4', '5'], null), false));
        $this->assertSame(['in'], array_map(static fn (ApiFilter $f) => $f->getConditionType(), $this->filters));
    }

    public function testItFiltersOutTheExcludedIdsItIsGivenWhileThePreparedComponentStillReadsTheRequest(): void
    {
        iterator_to_array($this->selection([[3]])->batches(10, null, ['7']));

        $this->assertSame(1, $this->prepared);
        $this->assertCount(1, $this->filters);
        $this->assertSame('nin', $this->filters[0]->getConditionType());
        $this->assertSame('submission_id', $this->filters[0]->getField());
    }

    public function testItAddsNoIdFilterForASelectAll(): void
    {
        iterator_to_array($this->selection([[1]])->batches(10, null, 'false'));

        $this->assertSame(1, $this->prepared);
        $this->assertSame([], $this->filters);
    }

    public function testItCastsSelectedAndExcludedIdsToIntegersAndDropsAnythingElse(): void
    {
        iterator_to_array($this->selection([[1]])->batches(10, ['4', '5; DROP', '0x1', ' 6', ['7'], 8, '9'], null));
        iterator_to_array($this->selection([[1]])->batches(10, null, ['12', 'x', '-3', '13']));

        $this->assertSame([4, 8, 9], $this->filters[0]->getValue());
        $this->assertSame([12, 13], $this->filters[1]->getValue());
    }

    public function testItRefusesASelectionThatHoldsNoValidIds(): void
    {
        $this->expectException(LocalizedException::class);
        iterator_to_array($this->selection([[1]])->batches(10, null, ['x', '1 OR 1']));
    }

    public function testItHintsTheFormKeysetIndexWhenTheGridIsFilteredByForm(): void
    {
        $this->where = ["(main_table.form_id = '10')"];
        iterator_to_array($this->selection([[1]])->batches(10, null, 'false'));

        $this->assertStringContainsString('AS `main_table` FORCE INDEX (`F`) WHERE', $this->sql[0]);
    }

    public function testItWalksThePrimaryKeyWhenNoFormIsFiltered(): void
    {
        iterator_to_array($this->selection([[1]])->batches(10, null, 'false'));

        $this->assertStringContainsString('AS `main_table` FORCE INDEX (PRIMARY) WHERE', $this->sql[0]);
    }
}
