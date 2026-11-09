<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Stats;

use Furan\FormBuilder\Model\Stats\Bucket;
use Furan\FormBuilder\Model\Stats\DeltaCalculator;
use Furan\FormBuilder\Model\Stats\SeriesSpec;
use PHPUnit\Framework\TestCase;

class DeltaCalculatorTest extends TestCase
{
    private DeltaCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DeltaCalculator(new Bucket());
    }

    public function testItCountsTheChosenOptionForChoiceFields(): void
    {
        $deltas = $this->calculator->compute(
            [['name' => 'colour', 'value' => 'red']],
            $this->dims(),
            ['colour' => $this->spec(SeriesSpec::KIND_CHOICE)],
            1
        );
        $this->assertSame([
            $this->delta('colour', $this->key('red'), 'red', 1),
            $this->submissions(1),
        ], $deltas);
    }

    public function testItIgnoresPayloadItemsWithoutASpec(): void
    {
        $deltas = $this->calculator->compute([['name' => 'x', 'value' => 'a']], $this->dims(), [], 1);
        $this->assertSame([$this->submissions(1)], $deltas);
    }

    public function testItCountsEveryDeliveredValueOnceAndTruncatesTheLabel(): void
    {
        $long = str_repeat('é', 300);
        $deltas = $this->calculator->compute(
            [['name' => 'c', 'value' => $long]],
            $this->dims(),
            ['c' => $this->spec(SeriesSpec::KIND_CHOICE)],
            1
        );
        $this->assertSame($this->key($long), $deltas[0]['v']);
        $this->assertSame(str_repeat('é', 255), $deltas[0]['l']);
    }

    public function testItCountsEverySelectedValuePlusAnAnsweredMarkerForMultiValueFields(): void
    {
        $deltas = $this->calculator->compute(
            [['name' => 'tags', 'value' => ['a', 'b', '']]],
            $this->dims(),
            ['tags' => $this->spec(SeriesSpec::KIND_MULTI)],
            1
        );
        $this->assertSame([
            $this->delta('tags', $this->key('a'), 'a', 1),
            $this->delta('tags', $this->key('b'), 'b', 1),
            $this->delta('tags', $this->key('__answered'), null, 1),
            $this->submissions(1),
        ], $deltas);
    }

    public function testItRecordsCountAndTotalForRatingAndNumberFields(): void
    {
        $deltas = $this->calculator->compute(
            [['name' => 'stars', 'value' => '4'], ['name' => 'qty', 'value' => 17.5]],
            $this->dims(),
            [
                'stars' => $this->spec(SeriesSpec::KIND_DISCRETE),
                'qty' => $this->spec(SeriesSpec::KIND_NUMBER, 5),
            ],
            1
        );
        $this->assertSame([
            $this->delta('stars', $this->key('4'), '4', 1),
            $this->delta('stars', null, null, 0, 1, 4.0),
            $this->delta('qty', $this->key('15'), '15', 1),
            $this->delta('qty', null, null, 0, 1, 17.5),
            $this->submissions(1),
        ], $deltas);
    }

    public function testItAssignsTheDayPeriodByConvertingTheUtcCreatedAtToTheStoreTimezone(): void
    {
        $dims = $this->dims(['created_at' => '2026-03-01 23:30:00', 'timezone' => 'Australia/Sydney']);
        $deltas = $this->calculator->compute([], $dims, [], 1);
        $this->assertSame('2026-03-02', $deltas[0]['p']);

        $dims = $this->dims(['created_at' => '2026-03-01 02:30:00', 'timezone' => 'America/Chicago']);
        $deltas = $this->calculator->compute([], $dims, [], 1);
        $this->assertSame('2026-02-28', $deltas[0]['p']);
    }

    public function testItCountsEachStoredMatrixAnswerStringUnderTheMatrixField(): void
    {
        $deltas = $this->calculator->compute(
            [['name' => 'grid', 'value' => ['Speed: Good', 'Price: Bad']]],
            $this->dims(),
            ['grid' => $this->spec(SeriesSpec::KIND_MATRIX)],
            1
        );
        $this->assertSame([
            $this->delta('grid', $this->key('Speed: Good'), 'Speed: Good', 1),
            $this->delta('grid', $this->key('Price: Bad'), 'Price: Bad', 1),
            $this->delta('grid', $this->key('__answered'), null, 1),
            $this->submissions(1),
        ], $deltas);
    }

    public function testItCountsTheCountryOfAnAddressAnswer(): void
    {
        $deltas = $this->calculator->compute(
            [['name' => 'addr', 'value' => $this->address()]],
            $this->dims(),
            ['addr' => $this->spec(SeriesSpec::KIND_ADDRESS)],
            1
        );
        $this->assertSame([$this->delta('addr', $this->key('NL'), 'NL', 1), $this->submissions(1)], $deltas);
    }

    public function testItBucketsDatesAndDateRanges(): void
    {
        $deltas = $this->calculator->compute(
            [
                ['name' => 'when', 'value' => '2026-05-17'],
                ['name' => 'stay', 'value' => ['start' => '2026-05-30', 'end' => '2026-06-02']],
                ['name' => 'bad', 'value' => 'nope'],
            ],
            $this->dims(),
            [
                'when' => $this->spec(SeriesSpec::KIND_DATE, 12),
                'stay' => $this->spec(SeriesSpec::KIND_DATE_RANGE, 1),
                'bad' => $this->spec(SeriesSpec::KIND_DATE, 1),
            ],
            1
        );
        $this->assertSame([
            $this->delta('when', $this->key('2026'), '2026', 1),
            $this->delta('stay', $this->key('2026-05'), '2026-05', 1),
            $this->delta('stay', null, null, 0, 1, 4.0),
            $this->submissions(1),
        ], $deltas);
    }

    public function testItMarksFreeTextAsAnsweredOnlyAndIgnoresKindNone(): void
    {
        $deltas = $this->calculator->compute(
            [['name' => 'msg', 'value' => 'hello'], ['name' => 'blank', 'value' => ' '], ['name' => 'f', 'value' => 'x']],
            $this->dims(),
            [
                'msg' => $this->spec(SeriesSpec::KIND_ANSWERED_ONLY),
                'blank' => $this->spec(SeriesSpec::KIND_ANSWERED_ONLY),
                'f' => $this->spec(SeriesSpec::KIND_NONE),
            ],
            1
        );
        $this->assertSame([$this->delta('msg', $this->key('__answered'), null, 1), $this->submissions(1)], $deltas);
    }

    public function testItProducesDayDeltasOnlyAndLeavesMonthRowsToTheFolder(): void
    {
        $deltas = $this->calculator->compute(
            [['name' => 'colour', 'value' => 'red']],
            $this->dims(['store_id' => 3, 'customer_group_id' => 2]),
            ['colour' => $this->spec(SeriesSpec::KIND_CHOICE)],
            1
        );
        foreach ($deltas as $delta) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/D', $delta['p']);
            $this->assertSame(3, $delta['s']);
            $this->assertSame(2, $delta['g']);
        }
        $this->assertCount(2, $deltas);
    }

    public function testItNegatesEveryDeltaForARemoval(): void
    {
        $payload = $this->fullPayload();
        $specs = $this->fullSpecs();
        $added = $this->calculator->compute($payload, $this->dims(), $specs, 1);
        $removed = $this->calculator->compute($payload, $this->dims(), $specs, -1);
        $this->assertNotEmpty($added);
        $this->assertCount(count($added), $removed);
        foreach ($added as $i => $delta) {
            foreach (['h', 'n', 't'] as $key) {
                $this->assertEquals(-$delta[$key], $removed[$i][$key]);
            }
            unset($delta['h'], $delta['n'], $delta['t']);
            $rest = $removed[$i];
            unset($rest['h'], $rest['n'], $rest['t']);
            $this->assertSame($delta, $rest);
        }
    }

    public function testItProducesIdenticalDeltasForAPayloadAndItsJsonRoundTrip(): void
    {
        $payload = $this->fullPayload();
        $roundTrip = json_decode((string)json_encode($payload), true);
        $this->assertSame(
            $this->calculator->compute($payload, $this->dims(), $this->fullSpecs(), 1),
            $this->calculator->compute($roundTrip, $this->dims(), $this->fullSpecs(), 1)
        );
    }

    private function fullPayload(): array
    {
        return [
            ['name' => 'colour', 'value' => 'red'],
            ['name' => 'n_int', 'value' => 4],
            ['name' => 'n_str', 'value' => '4'],
            ['name' => 'n_float', 'value' => 1234.5],
            ['name' => 'n_whole_float', 'value' => 7.0],
            ['name' => 'tags', 'value' => ['a', 'b']],
            ['name' => 'grid', 'value' => ['Speed: Good']],
            ['name' => 'addr', 'value' => $this->address()],
            ['name' => 'stay', 'value' => ['start' => '2026-05-30', 'end' => '2026-06-02']],
            ['name' => 'when', 'value' => '2026-05-17T10:00'],
        ];
    }

    private function fullSpecs(): array
    {
        return [
            'colour' => $this->spec(SeriesSpec::KIND_CHOICE),
            'n_int' => $this->spec(SeriesSpec::KIND_DISCRETE),
            'n_str' => $this->spec(SeriesSpec::KIND_DISCRETE),
            'n_float' => $this->spec(SeriesSpec::KIND_NUMBER, 0.5),
            'n_whole_float' => $this->spec(SeriesSpec::KIND_NUMBER),
            'tags' => $this->spec(SeriesSpec::KIND_MULTI),
            'grid' => $this->spec(SeriesSpec::KIND_MATRIX),
            'addr' => $this->spec(SeriesSpec::KIND_ADDRESS),
            'stay' => $this->spec(SeriesSpec::KIND_DATE_RANGE, 1),
            'when' => $this->spec(SeriesSpec::KIND_DATE, 1),
        ];
    }

    private function address(): array
    {
        return [
            'street' => ['1 Main St'], 'city' => 'Utrecht', 'region_id' => '', 'region' => '',
            'postcode' => '3511', 'country_id' => 'NL',
        ];
    }

    private function spec(int $kind, int|float|null $step = null): array
    {
        return ['kind' => $kind, 'bucket_step' => $step];
    }

    private function dims(array $override = []): array
    {
        return $override + [
            'form_id' => 1, 'store_id' => 0, 'customer_group_id' => 0,
            'created_at' => '2026-03-01 10:00:00', 'timezone' => 'UTC',
        ];
    }

    private function delta(string $k, ?string $v, ?string $l, int $h, int $n = 0, float $t = 0.0): array
    {
        return ['k' => $k, 'v' => $v, 'l' => $l, 'p' => '2026-03-01', 's' => 0, 'g' => 0, 'h' => $h, 'n' => $n, 't' => $t];
    }

    private function submissions(int $h): array
    {
        return $this->delta('__submissions', null, null, $h);
    }

    private function key(string $canonical): string
    {
        // phpcs:ignore Magento2.Security.InsecureFunction -- mirrors the production non-cryptographic md5 key
        return md5($canonical);
    }
}
