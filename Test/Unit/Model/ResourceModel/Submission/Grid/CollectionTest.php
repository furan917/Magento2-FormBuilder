<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\ResourceModel\Submission\Grid;

use Furan\FormBuilder\Model\ResourceModel\Submission\Grid\Collection;
use Furan\FormBuilder\Test\Unit\Helper\BlankSelectGridCollection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Platform\Quote;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Select\ColumnsRenderer;
use Magento\Framework\DB\Select\FromRenderer;
use Magento\Framework\DB\Select\LimitRenderer;
use Magento\Framework\DB\Select\OrderRenderer;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\DB\Select\WhereRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CollectionTest extends TestCase
{
    public function testTheGridSelectsListColumnsOnlyAndNeverThePayload(): void
    {
        $this->assertNotContains('payload_json', Collection::COLUMNS);
        $listing = simplexml_load_file(__DIR__ . '/../../../../../../view/adminhtml/ui_component/furan_formbuilder_submission_listing.xml');
        foreach ($listing->xpath('//columns/column/@name') as $name) {
            $this->assertContains((string)$name, Collection::COLUMNS);
        }
    }

    public function testItSelectsStatusAndCustomerGroupButNeverTheContext(): void
    {
        $this->assertContains('status', Collection::COLUMNS);
        $this->assertContains('customer_group_id', Collection::COLUMNS);
        $this->assertNotContains('context_json', Collection::COLUMNS);
    }

    public function testItSelectsTheReferenceColumnWhichIsNeitherSortableNorFiltered(): void
    {
        $this->assertContains('reference', Collection::COLUMNS);
        $listing = simplexml_load_file(__DIR__ . '/../../../../../../view/adminhtml/ui_component/furan_formbuilder_submission_listing.xml');
        $column = $listing->xpath('//columns/column[@name="reference"]');
        $this->assertCount(1, $column);
        $this->assertSame('false', (string)$column[0]->settings->sortable);
        $this->assertCount(0, $column[0]->settings->filter);
        $search = $listing->xpath('//listingToolbar/filterSearch[@name="fulltext"]');
        $this->assertCount(1, $search);
        $this->assertSame('Search by reference', (string)$search[0]->settings->placeholder);
    }

    /** @var list<string> */
    private array $counts = [];
    /** @var list<string> */
    private array $idQueries = [];
    /** @var list<string> */
    private array $pageQueries = [];

    private function selectRenderer(): SelectRenderer
    {
        $quote = new Quote();
        $renderer = static fn (string $part, int $sort, object $instance): array => ['renderer' => $instance, 'sort' => $sort, 'part' => $part];

        return new SelectRenderer([
            $renderer('columns', 200, new ColumnsRenderer($quote)),
            $renderer('from', 400, new FromRenderer($quote)),
            $renderer('where', 500, new WhereRenderer()),
            $renderer('order', 800, new OrderRenderer($quote)),
            $renderer('limitcount', 900, new LimitRenderer()),
        ]);
    }

    /**
     * @param list<int> $matching ids the filtered, ordered select yields; the count is their number unless $total is given
     */
    private function collection(int $page, int $pageSize, array $matching, ?int $total = null, string $class = Collection::class): Collection
    {
        $adapter = $this->createStub(Mysql::class);
        $adapter->method('quoteIdentifier')->willReturnCallback(static fn ($v) => '`' . implode('`.`', (array)$v) . '`');
        $adapter->method('quoteInto')->willReturnCallback(static fn ($text, $v) => str_replace('?', is_array($v) ? implode(',', $v) : (string)$v, $text));
        $adapter->method('prepareSqlCondition')->willReturnCallback(static fn ($field, $condition) => $field . ' = ' . json_encode($condition));
        $adapter->method('getQuoteIdentifierSymbol')->willReturn('`');
        $adapter->method('fetchOne')->willReturnCallback(function (string|Select $sql) use ($matching, $total): int {
            $this->counts[] = (string)$sql;
            return $total ?? count($matching);
        });
        $adapter->method('fetchCol')->willReturnCallback(function (string|Select $sql) use ($matching): array {
            $this->idQueries[] = $sql = (string)$sql;
            preg_match('/LIMIT (\d+)/', $sql, $limit);
            return array_slice($matching, 0, (int)$limit[1]);
        });
        $strategy = $this->createStub(FetchStrategyInterface::class);
        $strategy->method('fetchAll')->willReturnCallback(function (Select $select): array {
            $this->pageQueries[] = (string)$select;
            return [['submission_id' => 1]];
        });

        $select = new Select($adapter, $this->selectRenderer());
        $select->from(['main_table' => 'furan_formbuilder_submission'], Collection::COLUMNS);
        $collection = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        foreach (['_conn' => $adapter, '_select' => $select, '_fetchStrategy' => $strategy] as $name => $value) {
            $property = new \ReflectionProperty(AbstractDb::class, $name);
            $property->setValue($collection, $value);
        }
        $collection->setPageSize($pageSize);
        $collection->setCurPage($page);
        $collection->addFieldToFilter('main_table.form_id', 5);

        return $collection;
    }

    public function testItCountsAtMostTenThousandAndOneSubmissions(): void
    {
        $this->collection(1, 20, [1, 2])->getSize();

        $this->assertCount(1, $this->counts);
        $this->assertStringStartsWith('SELECT COUNT(*) FROM (SELECT `main_table`.`submission_id` FROM', $this->counts[0]);
        $this->assertStringContainsString('form_id', $this->counts[0]);
        $this->assertStringContainsString('LIMIT 10001', $this->counts[0]);
        $this->assertStringNotContainsString('ORDER BY', $this->counts[0]);
    }

    public function testItReportsTenThousandRecordsWhenMoreMatch(): void
    {
        $this->assertSame(10000, $this->collection(1, 20, [1], 10001)->getSize());
        $this->assertSame(7, $this->collection(1, 20, [1], 7)->getSize());
    }

    public function testItLoadsNothingForAPageThatStartsAtOrBeyondTheCap(): void
    {
        $collection = $this->collection(501, 20, [1, 2, 3], 20000);

        $this->assertSame([['submission_id' => 1]], $collection->getData());
        $this->assertSame([], $this->idQueries);
        $this->assertStringContainsString('1 = 0', $this->pageQueries[0]);
        $this->assertStringNotContainsString('IN (', $this->pageQueries[0]);
    }

    public function testItShowsOnlyTheRemainingRowsOnAShortLastPage(): void
    {
        $this->collection(2, 20, range(1, 25))->getData();

        $this->assertStringContainsString('IN (' . implode(',', range(21, 25)) . ')', $this->pageQueries[0]);
    }

    public function testItDoesNotRepeatRowsOnTheLastPageBeforeTheCap(): void
    {
        $this->collection(334, 30, range(1, 10050), 10050)->getData();

        $this->assertStringContainsString('IN (' . implode(',', range(9991, 10000)) . ')', $this->pageQueries[0]);
    }

    public function testItPagesWithoutOffsetByReadingAtMostTenThousandIdsAndLoadingThePageById(): void
    {
        $this->collection(3, 20, range(1, 100))->getData();

        $this->assertCount(1, $this->idQueries);
        $this->assertStringContainsString('LIMIT 60', $this->idQueries[0]);
        $this->assertStringContainsString('IN (' . implode(',', range(41, 60)) . ')', $this->pageQueries[0]);
        $this->assertStringNotContainsString('LIMIT', $this->pageQueries[0]);
        foreach ([...$this->counts, ...$this->idQueries, ...$this->pageQueries] as $sql) {
            $this->assertStringNotContainsString('OFFSET', $sql);
        }
    }

    public function testItNeverReadsMoreThanTenThousandIds(): void
    {
        $this->collection(4, 3000, range(1, 100), 50000)->getData();

        $this->assertStringContainsString('LIMIT 10000', $this->idQueries[0]);
    }

    /** @return array<string, array{string}> */
    public static function directions(): array
    {
        return ['ascending' => ['ASC'], 'descending' => ['DESC']];
    }

    #[DataProvider('directions')]
    public function testItBreaksSortTiesBySubmissionIdInTheDirectionOfTheSort(string $direction): void
    {
        $collection = $this->collection(1, 20, [1, 2]);
        $collection->addOrder('form_id', $direction);

        $collection->getData();

        $expected = 'ORDER BY form_id ' . $direction . ', `main_table`.`submission_id` ' . $direction;
        $this->assertStringContainsString($expected, $this->idQueries[0]);
        $this->assertStringContainsString($expected, $this->pageQueries[0]);
    }

    #[DataProvider('directions')]
    public function testItSortsBySubmissionIdWhenCreatedAtIsChosen(string $direction): void
    {
        $collection = $this->collection(1, 20, [1, 2]);
        $collection->addOrder('created_at', $direction);

        $collection->getData();

        foreach ([$this->idQueries[0], $this->pageQueries[0]] as $sql) {
            $this->assertStringNotContainsString('ORDER BY created_at', $sql);
            $this->assertStringContainsString('ORDER BY submission_id ' . $direction, $sql);
            $this->assertStringNotContainsString('main_table`.`submission_id` ' . $direction, $sql);
            $this->assertSame(1, substr_count($sql, 'ORDER BY'));
        }
    }

    public function testItForgetsThePageWindowAfterAResetAndAfterClear(): void
    {
        $collection = $this->collection(1, 20, range(1, 100));
        $collection->getData();
        $collection->clear()->resetData();
        $collection->setCurPage(2);

        $collection->getData();

        $this->assertCount(2, $this->idQueries);
        $this->assertStringContainsString('form_id', $this->pageQueries[1]);
        $this->assertStringContainsString('IN (' . implode(',', range(21, 40)) . ')', $this->pageQueries[1]);
        $this->assertStringNotContainsString('IN (' . implode(',', range(1, 20)) . ')', $this->pageQueries[1]);
        $this->assertSame(1, substr_count($this->pageQueries[1], 'ORDER BY'));
        $this->assertSame(1, substr_count($this->pageQueries[1], 'submission_id` DESC'));
    }

    public function testItForgetsThePageWindowAfterAReset(): void
    {
        $collection = $this->collection(1, 20, range(1, 100), null, BlankSelectGridCollection::class);
        $collection->getData();

        (new \ReflectionMethod($collection, '_reset'))->invoke($collection);

        $this->assertNull((new \ReflectionProperty(Collection::class, 'unwindowed'))->getValue($collection));
    }

    public function testItShowsTheNewestSubmissionsFirstWhenNoSortIsChosen(): void
    {
        $this->collection(1, 20, [1, 2])->getData();

        $this->assertStringContainsString('ORDER BY `main_table`.`submission_id` DESC', $this->idQueries[0]);
        $this->assertStringContainsString('ORDER BY `main_table`.`submission_id` DESC', $this->pageQueries[0]);
    }

    public function testItReportsTheFilteredCountAfterThePageIsLoaded(): void
    {
        $collection = $this->collection(1, 20, [1, 2], 7);

        $collection->getData();

        $this->assertSame(7, $collection->getSize());
        $this->assertSame(7, $collection->getTotalCount());
        $this->assertCount(1, $this->counts);
    }

    public function testItAppliesNoWindowWhenNoPageSizeIsSet(): void
    {
        $collection = $this->collection(1, 0, [1, 2]);

        $collection->getData();

        $this->assertSame([], $this->idQueries);
        $this->assertStringNotContainsString('IN (', $this->pageQueries[0]);
        $this->assertStringNotContainsString('LIMIT', $this->pageQueries[0]);
    }

    public function testItLeavesTheSelectThatMassActionsWalkWithoutThePageWindow(): void
    {
        $collection = $this->collection(3, 20, range(1, 100));
        $walked = clone $collection->getSelect();

        $collection->getData();

        $this->assertStringContainsString('form_id', (string)$walked);
        $this->assertStringNotContainsString('IN (', (string)$walked);
        $this->assertStringNotContainsString('LIMIT', (string)$walked);
        $this->assertStringNotContainsString('ORDER BY', (string)$walked);
    }

    public function testItWindowsThePageOnceEvenThoughLoadRendersTheLimitTwice(): void
    {
        $collection = $this->collection(3, 20, range(1, 100));
        $render = new \ReflectionMethod($collection, '_renderLimit');

        $render->invoke($collection);
        $render->invoke($collection);

        $this->assertCount(1, $this->idQueries);
        $this->assertSame(1, substr_count((string)$collection->getSelect(), 'main_table`.`submission_id` DESC'));
    }
}
