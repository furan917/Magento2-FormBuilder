<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Stats;

use Furan\FormBuilder\Model\Stats\Bucket;
use Furan\FormBuilder\Model\Stats\Histogram;
use Furan\FormBuilder\Model\Stats\SeriesSpec;
use PHPUnit\Framework\TestCase;

class HistogramTest extends TestCase
{
    /**
     * @param array<string, int> $hits
     * @return array<string, int>
     */
    private function bins(int $kind, int|float|null $step, array $hits): array
    {
        $bins = [];
        foreach ((new Histogram(new Bucket()))->bins($kind, $step, $hits) as $bin) {
            $bins[$bin['label']] = $bin['hits'];
        }
        return $bins;
    }

    public function testItSpreadsAgesAcrossRangeLabelledBinsSizedToTheData(): void
    {
        $bucket = new Bucket();
        $hits = [];
        foreach ([25, 27, 31, 44, 45, 52, 63, 80] as $age) {
            $label = $bucket->number($age, null);
            $hits[$label] = ($hits[$label] ?? 0) + 1;
        }

        $this->assertSame([
            '25-29' => 2, '30-34' => 1, '35-39' => 0, '40-44' => 1, '45-49' => 1, '50-54' => 1,
            '55-59' => 0, '60-64' => 1, '65-69' => 0, '70-74' => 0, '75-79' => 0, '80-84' => 1,
        ], $this->bins(SeriesSpec::KIND_NUMBER, null, $hits));
    }

    public function testItNeverSplitsAStoredBucketAcrossTwoBins(): void
    {
        $this->assertSame(
            ['0-3' => 3, '4-7' => 1, '8-11' => 0, '12-15' => 0, '16-19' => 0, '20-23' => 0, '24-27' => 0, '28-31' => 0, '32-35' => 0, '36-39' => 0, '40-43' => 2],
            $this->bins(SeriesSpec::KIND_NUMBER, 2.0, ['0' => 1, '2' => 2, '4' => 1, '40' => 2])
        );
        $this->assertSame(
            ['900-999' => 1, '1000-1099' => 4],
            $this->bins(SeriesSpec::KIND_NUMBER, null, ['990' => 1, '1000' => 4])
        );
    }

    public function testItLabelsSingleValuesAndFractionalBins(): void
    {
        $this->assertSame(['3' => 2, '4' => 0, '5' => 1], $this->bins(SeriesSpec::KIND_NUMBER, null, ['3' => 2, '5' => 1]));
        $this->assertSame(
            ['0.1-0.2' => 1, '0.2-0.3' => 0, '0.3-0.4' => 2],
            $this->bins(SeriesSpec::KIND_NUMBER, 0.1, ['0.1' => 1, '0.3' => 2])
        );
        $this->assertSame(['-20 - -11' => 1, '-10 - -1' => 0, '0-9' => 3], $this->bins(SeriesSpec::KIND_NUMBER, 10.0, ['-20' => 1, '0' => 3]));
        $this->assertSame(['0' => 4], $this->bins(SeriesSpec::KIND_NUMBER, null, ['0' => 4]));
        $this->assertSame([], $this->bins(SeriesSpec::KIND_NUMBER, null, []));
    }

    public function testItShowsDatesByMonthWhenTheyCoverMonthsAndByYearOnceTheyCoverYears(): void
    {
        $this->assertSame(
            ['2026-01' => 2, '2026-02' => 0, '2026-03' => 1, '2026-04' => 0, '2026-05' => 0, '2026-06' => 4],
            $this->bins(SeriesSpec::KIND_DATE, Bucket::MONTH, ['2026-06' => 4, '2026-01' => 2, '2026-03' => 1])
        );
        $this->assertSame(
            ['2023' => 1, '2024' => 0, '2025' => 3, '2026' => 1],
            $this->bins(SeriesSpec::KIND_DATE, Bucket::MONTH, ['2023-05' => 1, '2025-01' => 2, '2025-12' => 1, '2026-02' => 1])
        );
        $this->assertSame(
            [
                '1955-1959' => 1, '1960-1964' => 0, '1965-1969' => 0, '1970-1974' => 1, '1975-1979' => 1,
                '1980-1984' => 0, '1985-1989' => 0, '1990-1994' => 0, '1995-1999' => 0, '2000-2004' => 1,
            ],
            $this->bins(SeriesSpec::KIND_DATE_RANGE, Bucket::MONTH, ['1955-04' => 1, '1971-01' => 1, '1979-12' => 1, '2004-08' => 1])
        );
    }

    public function testItShowsDatesByDayOnlyWhenStoredByDayAndCoveringUnderTwoMonths(): void
    {
        $this->assertSame(
            ['2026-11-28' => 1, '2026-11-29' => 0, '2026-11-30' => 0, '2026-12-01' => 3],
            $this->bins(SeriesSpec::KIND_DATE, Bucket::DAY, ['2026-12-01' => 3, '2026-11-28' => 1])
        );
        $this->assertSame(
            ['2026-09' => 1, '2026-10' => 0, '2026-11' => 2],
            $this->bins(SeriesSpec::KIND_DATE, Bucket::DAY, ['2026-09-01' => 1, '2026-11-02' => 1, '2026-11-30' => 1])
        );
        $this->assertSame(['2025' => 1, '2026' => 2], $this->bins(SeriesSpec::KIND_DATE, 12, ['2025' => 1, '2026' => 2]));
    }
}
