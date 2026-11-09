<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Validation;

use Furan\FormBuilder\Model\ResourceModel\SavedRule\Collection;
use Furan\FormBuilder\Model\ResourceModel\SavedRule\CollectionFactory;
use Furan\FormBuilder\Model\Validation\SavedRuleList;
use Furan\FormBuilder\Model\Validation\SavedRuleManager;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;

class SavedRuleListTest extends TestCase
{
    private function rows(): array
    {
        $rows = [];
        foreach (['Booking code' => 'booking', 'Account number' => 'account', 'Zip' => 'zip', 'Coupon' => 'coupon'] as $label => $code) {
            $rows[] = ['id' => count($rows) + 1, 'code' => $code, 'label' => $label, 'pattern' => '[0-9]+', 'example' => 'EX-' . $code,
                'updated_at' => '2026-11-0' . (count($rows) + 1) . ' 10:00:00'];
        }
        return $rows;
    }

    private function listing(array $params, array $counts = ['booking' => 3, 'zip' => 1]): array
    {
        return SavedRuleList::apply($this->rows(), $counts, SavedRuleList::filters($params));
    }

    public function testItSortsByNameAndPagesTheList(): void
    {
        $result = $this->listing(['limit' => '20']);
        $this->assertSame(['Account number', 'Booking code', 'Coupon', 'Zip'], array_column($result['rows'], 'label'));
        $this->assertSame([4, 4, 1, 1, 1, 4], [$result['total'], $result['all'], $result['page'], $result['pages'], $result['from'], $result['to']]);
    }

    public function testItSearchesNameCodeExampleAndPatternCaseInsensitively(): void
    {
        $this->assertSame(['Booking code'], array_column($this->listing(['q' => 'BOOK'])['rows'], 'label'));
        $this->assertSame(['Zip'], array_column($this->listing(['q' => 'ex-zip'])['rows'], 'label'));
        $this->assertSame(4, $this->listing(['q' => '[0-9]'])['total']);
    }

    public function testItFiltersByUsageAndSortsByMostUsedOrRecentlyUpdated(): void
    {
        $this->assertSame(['Booking code', 'Zip'], array_column($this->listing(['usage' => 'used', 'sort' => 'usage'])['rows'], 'label'));
        $this->assertSame(['Account number', 'Coupon'], array_column($this->listing(['usage' => 'unused'])['rows'], 'label'));
        $this->assertSame(['Coupon', 'Zip', 'Account number', 'Booking code'], array_column($this->listing(['sort' => 'updated'])['rows'], 'label'));
        $this->assertSame(3, $this->listing([])['rows'][1]['used']);
    }

    public function testItClampsThePageAndIgnoresUnknownFilterValues(): void
    {
        $f = SavedRuleList::filters(['q' => ['x'], 'usage' => 'evil', 'sort' => 'drop table', 'limit' => '7', 'p' => '-3']);
        $this->assertSame(['q' => '', 'usage' => 'all', 'sort' => 'name', 'limit' => 20, 'page' => 1], $f);
        $this->assertSame(100, mb_strlen(SavedRuleList::filters(['q' => str_repeat('a', 500)])['q']));

        $rows = array_merge(...array_fill(0, 30, $this->rows()));
        $last = SavedRuleList::apply($rows, [], SavedRuleList::filters(['p' => '99']));
        $this->assertSame([6, 6, 101, 120], [$last['page'], $last['pages'], $last['from'], $last['to']]);
    }

    public function testItCountsUsageOnlyForTheLoadedRules(): void
    {
        $rules = array_map(static fn (array $r): DataObject => new DataObject(['rule_id' => $r['id']] + $r), $this->rows());
        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToSelect')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rules));
        $collections = $this->createStub(CollectionFactory::class);
        $collections->method('create')->willReturn($collection);
        $manager = $this->createMock(SavedRuleManager::class);
        $manager->expects($this->once())->method('usageCounts')
            ->with(['booking', 'account', 'zip', 'coupon'])->willReturn(['zip' => 2]);

        $result = (new SavedRuleList($collections, $manager))->page(SavedRuleList::filters(['usage' => 'used']));

        $this->assertSame([['Zip', 2]], array_map(static fn (array $r): array => [$r['label'], $r['used']], $result['rows']));
    }
}
