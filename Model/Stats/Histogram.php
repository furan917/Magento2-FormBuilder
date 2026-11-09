<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Stats;

/**
 * Pure: merges the stored buckets of a number or date series into display bins sized to the values present.
 * Bins are whole multiples of the stored resolution, so a stored bucket never straddles two bins.
 */
class Histogram
{
    private const MAX_BINS = 12;
    private const MAX_DAY_SPAN = 62;
    private const MAX_MONTH_SPAN = 731;

    public function __construct(private readonly Bucket $bucket)
    {
    }

    /**
     * @param int|float|null $step frozen series step
     * @param array<int|string, int> $hits stored bucket label => hits
     * @return list<array{label: string, hits: int}> ascending, empty bins included
     */
    public function bins(int $kind, int|float|null $step, array $hits): array
    {
        return $kind === SeriesSpec::KIND_NUMBER
            ? $this->numberBins($step === null ? null : (float)$step, $hits)
            : $this->dateBins($hits);
    }

    /**
     * @param array<int|string, int> $hits
     * @return list<array{label: string, hits: int}>
     */
    private function numberBins(?float $step, array $hits): array
    {
        $values = [];
        $base = $step ?? 0.0;
        $integral = true;
        foreach ($hits as $label => $count) {
            if (!is_numeric($label)) {
                continue;
            }
            $value = (float)$label;
            $values[] = [$value, $count];
            $base = max($base, $step ?? $this->bucket->significantWidth($value));
            $integral = $integral && floor($value) === $value;
        }
        if ($values === []) {
            return [];
        }
        if ($integral) {
            $base = max($base, 1.0);
        }
        $lows = array_column($values, 0);
        $width = $this->width($base, min($lows), max($lows));
        $counts = [];
        foreach ($values as [$value, $count]) {
            $index = $this->index($value, $width);
            $counts[$index] = ($counts[$index] ?? 0) + $count;
        }
        $bins = [];
        $last = max(array_keys($counts));
        for ($index = min(array_keys($counts)); $index <= $last; $index++) {
            $bins[] = ['label' => $this->rangeLabel($index * $width, $width), 'hits' => $counts[$index] ?? 0];
        }
        return $bins;
    }

    /** The smallest base x 1, 2, 5, 10, 20, 50... that fits the values in MAX_BINS. */
    private function width(float $base, float $min, float $max): float
    {
        for ($scale = 1.0;; $scale *= 10) {
            foreach ([1, 2, 5] as $mantissa) {
                $width = $base * $mantissa * $scale;
                if ($this->index($max, $width) - $this->index($min, $width) + 1 <= self::MAX_BINS) {
                    return $width;
                }
            }
        }
    }

    private function index(float $value, float $width): int
    {
        return (int)floor(round($value / $width, 9));
    }

    private function rangeLabel(float $low, float $width): string
    {
        $wholeStep = floor($width) === $width;
        if ($wholeStep && $width === 1.0) {
            return $this->bucket->format($low);
        }
        $high = $wholeStep ? $low + $width - 1 : $low + $width;
        return $this->bucket->format($low) . ($low < 0 ? ' - ' : '-') . $this->bucket->format($high);
    }

    /**
     * Labels are YYYY, YYYY-MM or YYYY-MM-DD; the coarsest stored resolution present is the finest shown.
     *
     * @param array<int|string, int> $hits
     * @return list<array{label: string, hits: int}>
     */
    private function dateBins(array $hits): array
    {
        $dates = [];
        $coarsest = Bucket::DAY;
        foreach ($hits as $label => $count) {
            $label = (string)$label;
            if (preg_match('/^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?$/D', $label, $m) !== 1) {
                continue;
            }
            $resolution = isset($m[3]) ? Bucket::DAY : (isset($m[2]) ? Bucket::MONTH : Bucket::YEAR);
            $coarsest = max($coarsest, $resolution);
            $dates[] = [(int)$m[1], (int)($m[2] ?? 1), (int)($m[3] ?? 1), $count];
        }
        if ($dates === []) {
            return [];
        }
        usort($dates, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        $first = $dates[0];
        $last = $dates[count($dates) - 1];
        $span = (int)((gmmktime(0, 0, 0, $last[1], $last[2], $last[0]) - gmmktime(0, 0, 0, $first[1], $first[2], $first[0])) / 86400);

        if ($coarsest === Bucket::DAY && $span < self::MAX_DAY_SPAN) {
            return $this->calendarBins($dates, 'P1D', 'Y-m-d');
        }
        if ($coarsest <= Bucket::MONTH && $span < self::MAX_MONTH_SPAN) {
            return $this->calendarBins($dates, 'P1M', 'Y-m');
        }
        $years = [];
        foreach ($dates as [$year, , , $count]) {
            $years[(string)$year] = ($years[(string)$year] ?? 0) + $count;
        }
        return $this->numberBins(1.0, $years);
    }

    /**
     * @param list<array{int, int, int, int}> $dates sorted year, month, day, hits
     * @return list<array{label: string, hits: int}>
     */
    private function calendarBins(array $dates, string $interval, string $format): array
    {
        $counts = [];
        foreach ($dates as [$year, $month, $day, $count]) {
            $key = sprintf('%04d-%02d', $year, $month) . ($format === 'Y-m-d' ? sprintf('-%02d', $day) : '');
            $counts[$key] = ($counts[$key] ?? 0) + $count;
        }
        $first = $dates[0];
        $start = sprintf('%04d-%02d-%02d', $first[0], $first[1], $format === 'Y-m-d' ? $first[2] : 1);
        $cursor = new \DateTimeImmutable($start . ' 00:00:00 UTC');
        $end = (string)array_key_last($counts);
        $step = new \DateInterval($interval);
        $bins = [];
        do {
            $key = $cursor->format($format);
            $bins[] = ['label' => $key, 'hits' => $counts[$key] ?? 0];
            $cursor = $cursor->add($step);
        } while ($key < $end);
        return $bins;
    }
}
