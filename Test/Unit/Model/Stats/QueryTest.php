<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Stats;

use Furan\FormBuilder\Model\Stats\ChartShape;
use Furan\FormBuilder\Model\Stats\Query;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\TestCase;

class QueryTest extends TestCase
{
    private const PREFIX = 'furan_formbuilder_';

    /** @var array<string, array> rows returned per table suffix */
    private array $tables = [];
    /** @var array<int, array{string, string, array}> table, sql, bind */
    private array $calls = [];
    private Query $query;

    protected function setUp(): void
    {
        $this->calls = [];
        $this->tables = [
            'stat_field' => [
                ['field_id' => '1', 'field_key' => 'colour', 'kind' => '1', 'bucket_step' => null],
                ['field_id' => '2', 'field_key' => 'score', 'kind' => '3', 'bucket_step' => null],
                ['field_id' => '3', 'field_key' => 'qty', 'kind' => '4', 'bucket_step' => '10'],
                ['field_id' => '4', 'field_key' => 'when', 'kind' => '5', 'bucket_step' => '1'],
                ['field_id' => '5', 'field_key' => 'gone', 'kind' => '1', 'bucket_step' => null],
                ['field_id' => '6', 'field_key' => 'tags', 'kind' => '2', 'bucket_step' => null],
            ],
            'stat_value' => [],
            'stat_number' => [],
            'stat_day' => [],
            'stat_label' => [],
        ];
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchAll')->willReturnCallback(function (string $sql, array $bind = []): array {
            foreach (array_keys($this->tables) as $table) {
                if (str_contains($sql, ' ' . self::PREFIX . $table . ' ')) {
                    $this->calls[] = [$table, $sql, $bind];
                    return $this->tables[$table];
                }
            }
            return [];
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new \DateTime('2026-11-07 10:00:00'));
        $lists = $this->createStub(\Magento\Framework\Locale\ListsInterface::class);
        $lists->method('getOptionCountries')->willReturn([
            ['value' => 'GB', 'label' => 'United Kingdom'],
            ['value' => 'DE', 'label' => 'Germany'],
        ]);
        $this->query = new Query($resource, $timezone, new ChartShape(new \Furan\FormBuilder\Model\FieldType\Pool([
            'country' => new \Furan\FormBuilder\Model\FieldType\Country(
                $this->createStub(\Magento\Directory\Model\AllowedCountries::class),
                $lists
            ),
        ]), new \Furan\FormBuilder\Model\Stats\Histogram(new \Furan\FormBuilder\Model\Stats\Bucket())));
    }

    private function fields(): array
    {
        return [
            ['name' => 'colour', 'type' => 'select', 'label' => 'Favourite colour', 'options' => [
                ['value' => 'red', 'label' => 'Red'],
                ['value' => 'blue', 'label' => 'Blue (new)'],
            ]],
            ['name' => 'score', 'type' => 'rating', 'label' => 'Score'],
            ['name' => 'qty', 'type' => 'number', 'label' => 'Quantity'],
            ['name' => 'when', 'type' => 'date', 'label' => 'When'],
            ['name' => 'tags', 'type' => 'checkboxes', 'label' => 'Tags', 'options' => [
                ['value' => 'a', 'label' => 'Alpha'],
            ]],
        ];
    }

    private static function key(string $canonical): string
    {
        // phpcs:ignore Magento2.Security.InsecureFunction -- mirrors the production non-cryptographic md5 key
        return strtoupper(md5($canonical));
    }

    private function callsTo(string $table): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === $table));
    }

    private function field(array $result, string $key): array
    {
        foreach ($result['fields'] as $chart) {
            if ($chart['key'] === $key) {
                return $chart;
            }
        }
        $this->fail('No chart for ' . $key);
    }

    public function testItReturnsOptionCountsAndPercentagesForChoiceFields(): void
    {
        $this->tables['stat_value'] = [
            ['field_id' => '1', 'value_key' => self::key('red'), 'hits' => '3'],
            ['field_id' => '1', 'value_key' => self::key('blue'), 'hits' => '1'],
            ['field_id' => '1', 'value_key' => self::key('zero'), 'hits' => '0'],
            ['field_id' => '6', 'value_key' => self::key('a'), 'hits' => '2'],
            ['field_id' => '6', 'value_key' => self::key('__answered'), 'hits' => '4'],
        ];
        $this->tables['stat_label'] = [
            ['field_id' => '1', 'value_key' => self::key('red'), 'label' => 'Red old'],
            ['field_id' => '1', 'value_key' => self::key('blue'), 'label' => 'blue'],
        ];

        $result = $this->query->charts(9, $this->fields());

        $colour = $this->field($result, 'colour');
        $this->assertSame('choice', $colour['type']);
        $this->assertSame('Favourite colour', $colour['label']);
        $this->assertSame(
            [['label' => 'Red', 'hits' => 3, 'percent' => 75.0], ['label' => 'Blue (new)', 'hits' => 1, 'percent' => 25.0]],
            $colour['options']
        );
        $tags = $this->field($result, 'tags');
        $this->assertSame([['label' => 'Alpha', 'hits' => 2, 'percent' => 50.0]], $tags['options']);
        $this->assertSame(4, $tags['answered']);
    }

    public function testItShowsCountryNamesForCountryFieldsAndFallsBackToTheCode(): void
    {
        $this->tables['stat_field'] = [['field_id' => '7', 'field_key' => 'where', 'kind' => '1', 'bucket_step' => null]];
        $this->tables['stat_value'] = [
            ['field_id' => '7', 'value_key' => self::key('GB'), 'hits' => '3'],
            ['field_id' => '7', 'value_key' => self::key('ZZ'), 'hits' => '1'],
        ];
        $this->tables['stat_label'] = [
            ['field_id' => '7', 'value_key' => self::key('GB'), 'label' => 'GB'],
            ['field_id' => '7', 'value_key' => self::key('ZZ'), 'label' => 'ZZ'],
        ];

        $result = $this->query->charts(9, [['name' => 'where', 'type' => 'country', 'label' => 'Where']]);

        $this->assertSame(['United Kingdom', 'ZZ'], array_column($this->field($result, 'where')['options'], 'label'));
    }

    public function testItReturnsRemovedFieldsSeparately(): void
    {
        $this->tables['stat_value'] = [['field_id' => '5', 'value_key' => self::key('x'), 'hits' => '2']];
        $this->tables['stat_label'] = [['field_id' => '5', 'value_key' => self::key('x'), 'label' => 'X']];

        $result = $this->query->charts(9, $this->fields());

        $this->assertSame('gone', $result['removed'][0]['key']);
        $this->assertSame('X', $result['removed'][0]['options'][0]['label']);
    }

    public function testItReturnsTheAverageAndDistributionForRatingFields(): void
    {
        $this->tables['stat_value'] = [
            ['field_id' => '2', 'value_key' => self::key('5'), 'hits' => '1'],
            ['field_id' => '2', 'value_key' => self::key('3'), 'hits' => '3'],
        ];
        $this->tables['stat_number'] = [['field_id' => '2', 'n' => '4', 'total' => '14']];
        $this->tables['stat_label'] = [
            ['field_id' => '2', 'value_key' => self::key('5'), 'label' => '5'],
            ['field_id' => '2', 'value_key' => self::key('3'), 'label' => '3'],
        ];

        $score = $this->field($this->query->charts(9, $this->fields()), 'score');

        $this->assertSame('rating', $score['type']);
        $this->assertSame(3.5, $score['average']);
        $this->assertSame(4, $score['n']);
        $this->assertSame(
            [['label' => '3', 'hits' => 3, 'percent' => 75.0], ['label' => '5', 'hits' => 1, 'percent' => 25.0]],
            $score['options']
        );
    }

    public function testItReturnsHistogramBucketsForNumberAndDateFields(): void
    {
        $this->tables['stat_value'] = [
            ['field_id' => '3', 'value_key' => self::key('20'), 'hits' => '2'],
            ['field_id' => '3', 'value_key' => self::key('0'), 'hits' => '5'],
            ['field_id' => '4', 'value_key' => self::key('2026-03'), 'hits' => '1'],
        ];
        $this->tables['stat_number'] = [['field_id' => '3', 'n' => '7', 'total' => '70']];
        $this->tables['stat_label'] = [
            ['field_id' => '3', 'value_key' => self::key('20'), 'label' => '20'],
            ['field_id' => '3', 'value_key' => self::key('0'), 'label' => '0'],
            ['field_id' => '4', 'value_key' => self::key('2026-03'), 'label' => '2026-03'],
        ];

        $result = $this->query->charts(9, $this->fields());

        $qty = $this->field($result, 'qty');
        $this->assertSame('histogram', $qty['type']);
        $this->assertSame(10.0, $qty['average']);
        $this->assertSame(
            [['label' => '0-9', 'hits' => 5], ['label' => '10-19', 'hits' => 0], ['label' => '20-29', 'hits' => 2]],
            $qty['buckets']
        );
        $this->assertSame([['label' => '2026-03', 'hits' => 1]], $this->field($result, 'when')['buckets']);
    }

    public function testItReturnsSubmissionsPerDayForTheTimeChart(): void
    {
        $this->tables['stat_day'] = [
            ['period' => '2026-11-05', 'submissions' => '2'],
            ['period' => '2026-11-03', 'submissions' => '1'],
        ];

        $result = $this->query->charts(9, $this->fields(), ['from' => '2026-11-01', 'to' => '2026-11-07']);

        $this->assertSame('day', $result['granularity']);
        $this->assertSame(
            [
                ['period' => '2026-11-01', 'submissions' => 0],
                ['period' => '2026-11-02', 'submissions' => 0],
                ['period' => '2026-11-03', 'submissions' => 1],
                ['period' => '2026-11-04', 'submissions' => 0],
                ['period' => '2026-11-05', 'submissions' => 2],
                ['period' => '2026-11-06', 'submissions' => 0],
                ['period' => '2026-11-07', 'submissions' => 0],
            ],
            $result['time']
        );
        $this->assertSame(3, $result['submissions']);
        $this->assertSame([9, 0, 1, '2026-11-01', '2026-11-07'], $this->callsTo('stat_day')[0][2]);
    }

    public function testItSumsMonthPointsForRangesLongerThanNinetyTwoDays(): void
    {
        $this->tables['stat_day'] = [
            ['period' => '2026-01-20', 'submissions' => '2'],
            ['period' => '2026-01-25', 'submissions' => '1'],
            ['period' => '2026-02-01', 'submissions' => '4'],
        ];

        $result = $this->query->charts(9, $this->fields(), ['from' => '2026-01-15', 'to' => '2026-11-07']);

        $this->assertSame('month', $result['granularity']);
        $this->assertSame(
            ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'],
            array_column($result['time'], 'period')
        );
        $this->assertSame([3, 4, 0, 0, 0, 0, 0, 0, 0, 0, 0], array_column($result['time'], 'submissions'));
    }

    public function testItFiltersByStoreViewCustomerGroupAndDateRange(): void
    {
        $this->query->charts(9, $this->fields(), [
            'store_id' => 2,
            'customer_group_id' => 0,
            'from' => '2026-11-01',
            'to' => '2026-11-05',
        ]);

        [, $sql, $bind] = $this->callsTo('stat_value')[0];
        $this->assertStringContainsString('store_id = ?', $sql);
        $this->assertStringContainsString('customer_group_id = ?', $sql);
        $this->assertSame([0, 0, '2026-11-01', '2026-11-05', 2, 0], array_slice($bind, -6));
    }

    public function testItReadsMonthRowsForWholeMonthsInsideTheRange(): void
    {
        $this->query->charts(9, $this->fields(), ['from' => '2026-01-15', 'to' => '2026-11-07']);

        $bind = $this->callsTo('stat_value')[0][2];
        $this->assertSame(
            [0, 1, '2026-01-15', '2026-01-31', 1, 1, '2026-02-01', '2026-11-01'],
            array_slice($bind, -8)
        );
    }

    public function testItReadsOnlyRollupRowsWhenNoStoreOrCustomerGroupFilterIsSet(): void
    {
        $this->query->charts(9, $this->fields());

        foreach (['stat_value', 'stat_number', 'stat_day'] as $table) {
            foreach ($this->callsTo($table) as [, $sql, $bind]) {
                $this->assertStringNotContainsString('store_id', $sql);
                $this->assertStringContainsString('is_rollup = ?', $sql);
                $this->assertSame([1, 1, '2025-12-01', '2026-11-01'], array_slice($bind, -4));
            }
        }
    }

    public function testItReadsOnlyDetailRowsWhenAStoreOrCustomerGroupFilterIsSet(): void
    {
        $this->query->charts(9, $this->fields(), ['customer_group_id' => 3]);

        foreach (['stat_value', 'stat_number', 'stat_day'] as $table) {
            foreach ($this->callsTo($table) as [, $sql, $bind]) {
                $this->assertStringNotContainsString('store_id = ?', $sql);
                $this->assertStringContainsString('customer_group_id = ?', $sql);
                $this->assertSame([1, 0, '2025-12-01', '2026-11-01', 3], array_slice($bind, -5));
            }
        }
    }

    public function testItSwapsAReversedDateRangeInsteadOfFailing(): void
    {
        $result = $this->query->charts(9, $this->fields(), ['from' => '2026-11-05', 'to' => '2026-11-01']);

        $this->assertSame('2026-11-01', $result['from']);
        $this->assertSame('2026-11-05', $result['to']);
        [, $sql] = $this->callsTo('stat_day')[0];
        $this->assertStringNotContainsString('()', $sql);
    }

    public function testItCapsTheRangeToFiveYears(): void
    {
        $result = $this->query->charts(9, $this->fields(), ['from' => '1990-01-01', 'to' => '2026-11-07']);

        $this->assertSame('2021-11-08', $result['from']);
        $this->assertSame('2026-11-07', $result['to']);
        $this->assertCount(61, $result['time']);
    }

    public function testItFallsBackToTheDefaultRangeForMalformedDates(): void
    {
        $result = $this->query->charts(9, $this->fields(), ['from' => 'yesterday', 'to' => '2026-13-45']);

        $this->assertSame('2025-12-01', $result['from']);
        $this->assertSame('2026-11-07', $result['to']);
    }
}
