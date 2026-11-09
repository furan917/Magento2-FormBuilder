<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Stats;

use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\Stats\Bucket;
use Furan\FormBuilder\Model\Stats\SeriesSpec;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Test\Unit\Helper\StatsType;
use PHPUnit\Framework\TestCase;

class SeriesSpecTest extends TestCase
{
    private SeriesSpec $spec;

    protected function setUp(): void
    {
        $this->spec = new SeriesSpec(FieldPools::builtIn());
    }

    public function testItClassifiesFieldsByTypeCodeSoSuggestAnswersAreCountedAsAnsweredOnly(): void
    {
        $expected = [
            'select' => SeriesSpec::KIND_CHOICE, 'radio' => SeriesSpec::KIND_CHOICE,
            'cards' => SeriesSpec::KIND_CHOICE, 'toggle' => SeriesSpec::KIND_CHOICE,
            'checkbox' => SeriesSpec::KIND_CHOICE, 'consent' => SeriesSpec::KIND_CHOICE,
            'country' => SeriesSpec::KIND_CHOICE,
            'checkboxes' => SeriesSpec::KIND_MULTI, 'multiselect' => SeriesSpec::KIND_MULTI,
            'rating' => SeriesSpec::KIND_DISCRETE, 'scale' => SeriesSpec::KIND_DISCRETE,
            'number' => SeriesSpec::KIND_NUMBER,
            'date' => SeriesSpec::KIND_DATE, 'month' => SeriesSpec::KIND_DATE, 'datetime' => SeriesSpec::KIND_DATE,
            'date_range' => SeriesSpec::KIND_DATE_RANGE,
            'matrix' => SeriesSpec::KIND_MATRIX, 'address' => SeriesSpec::KIND_ADDRESS,
            'text' => SeriesSpec::KIND_ANSWERED_ONLY, 'textarea' => SeriesSpec::KIND_ANSWERED_ONLY,
            'email' => SeriesSpec::KIND_ANSWERED_ONLY, 'tel' => SeriesSpec::KIND_ANSWERED_ONLY,
            'url' => SeriesSpec::KIND_ANSWERED_ONLY, 'suggest' => SeriesSpec::KIND_ANSWERED_ONLY,
            'list' => SeriesSpec::KIND_ANSWERED_ONLY, 'vendor_custom' => SeriesSpec::KIND_ANSWERED_ONLY,
            'map' => SeriesSpec::KIND_NONE, 'file' => SeriesSpec::KIND_NONE, 'hidden' => SeriesSpec::KIND_NONE,
        ];
        foreach ($expected as $type => $kind) {
            $this->assertSame($kind, $this->spec->specFor(['type' => $type])['kind'], $type);
        }
        $this->assertSame(SeriesSpec::KIND_ANSWERED_ONLY, $this->spec->specFor([])['kind']);
        $this->assertSame(
            ['kind' => SeriesSpec::KIND_DISCRETE, 'bucket_step' => null],
            $this->spec->specFor(['type' => 'range', 'settings' => ['min' => 0, 'max' => 20, 'step' => 1]])
        );
    }

    public function testItDerivesSeriesSpecsWithABoundedNumberOfBuckets(): void
    {
        $number = static fn(array $rules): array => ['type' => 'number', 'rules' => $rules];
        $spec = $this->spec->specFor($number([['rule' => 'min', 'value' => 0], ['rule' => 'max', 'value' => 100]]));
        $this->assertSame(SeriesSpec::KIND_NUMBER, $spec['kind']);
        $this->assertSame(5.0, $spec['bucket_step']);

        $this->assertSame(1.0, $this->spec->specFor(
            $number([['rule' => 'min', 'value' => 0], ['rule' => 'max', 'value' => 40]])
        )['bucket_step']);
        $this->assertNull($this->spec->specFor($number([['rule' => 'min', 'value' => 0]]))['bucket_step']);
        $this->assertNull($this->spec->specFor($number([]))['bucket_step']);
        $this->assertNull($this->spec->specFor(
            $number([['rule' => 'min', 'value' => 10], ['rule' => 'max', 'value' => 1]])
        )['bucket_step']);

        foreach ([[0, 49], [0, 50], [-1000, 1000], [3, 7777], [0, 0.5], [0, 1e9]] as [$min, $max]) {
            $step = $this->spec->specFor(
                $number([['rule' => 'min', 'value' => $min], ['rule' => 'max', 'value' => $max]])
            )['bucket_step'];
            $this->assertLessThanOrEqual(50, floor($max / $step) - floor($min / $step) + 1, "$min..$max");
        }

        $range = $this->spec->specFor(['type' => 'range', 'settings' => ['min' => 0, 'max' => 100, 'step' => 1]]);
        $this->assertSame(SeriesSpec::KIND_NUMBER, $range['kind']);
        $this->assertSame(5.0, $range['bucket_step']);

        $date = static fn(string $min, string $max): array => [
            'type' => 'date',
            'rules' => [['rule' => 'min_date', 'value' => $min], ['rule' => 'max_date', 'value' => $max]],
        ];
        $this->assertSame(Bucket::DAY, $this->spec->specFor($date('2026-11-01', '2027-01-31'))['bucket_step']);
        $this->assertSame(Bucket::MONTH, $this->spec->specFor($date('2026-11-01', '2027-02-01'))['bucket_step']);
        $this->assertSame(Bucket::MONTH, $this->spec->specFor($date('2024-01-01', '2029-01-01'))['bucket_step']);
        $this->assertSame(Bucket::MONTH, $this->spec->specFor($date('2027-01-01', '2026-01-01'))['bucket_step']);
        $this->assertSame(Bucket::MONTH, $this->spec->specFor(['type' => 'date'])['bucket_step']);
        $this->assertSame(Bucket::MONTH, $this->spec->specFor(['type' => 'date_range'])['bucket_step']);
        $this->assertNull($this->spec->specFor(['type' => 'select'])['bucket_step']);
    }

    private function specWith(?int $kind, array $field = []): array
    {
        return (new SeriesSpec(new Pool(['stat' => new StatsType($kind)])))->specFor($field + ['type' => 'stat']);
    }

    public function testItChartsACustomTypeUsingTheKindReturnedByStatsKind(): void
    {
        $this->assertSame(
            ['kind' => SeriesSpec::KIND_MULTI, 'bucket_step' => null],
            $this->specWith(SeriesSpec::KIND_MULTI)
        );
        $this->assertSame(5.0, $this->specWith(SeriesSpec::KIND_NUMBER, ['rules' => [
            ['rule' => 'min', 'value' => 0], ['rule' => 'max', 'value' => 100],
        ]])['bucket_step']);
    }

    public function testItSkipsACustomTypeWhoseStatsKindReturnsNull(): void
    {
        $this->assertSame(SeriesSpec::KIND_NONE, $this->specWith(null)['kind']);
    }

    public function testItTreatsAnUnrecognisedKindAsNoChart(): void
    {
        $this->assertSame(SeriesSpec::KIND_NONE, $this->specWith(99)['kind']);
        $this->assertSame(SeriesSpec::KIND_NONE, $this->specWith(-1)['kind']);
    }

    public function testItKeepsTodaysChartKindsForEveryBuiltInType(): void
    {
        $kinds = [];
        foreach (FieldPools::builtIn()->all() as $code => $type) {
            $kinds[$code] = $this->spec->specFor(['type' => $code])['kind'];
        }
        ksort($kinds);
        $this->assertSame([
            'address' => 8, 'cards' => 1, 'checkbox' => 1, 'checkboxes' => 2, 'color' => 9, 'consent' => 1,
            'country' => 1, 'date' => 5, 'date_range' => 6, 'datetime' => 5, 'email' => 9, 'file' => 0,
            'hidden' => 0, 'list' => 9, 'map' => 0, 'matrix' => 7, 'month' => 5, 'multiselect' => 2,
            'newsletter' => 1, 'number' => 4, 'radio' => 1, 'range' => 4, 'rating' => 3, 'scale' => 3, 'select' => 1,
            'suggest' => 9, 'tel' => 9, 'text' => 9, 'textarea' => 9, 'time' => 9, 'toggle' => 1, 'url' => 9,
        ], $kinds);
    }
}
