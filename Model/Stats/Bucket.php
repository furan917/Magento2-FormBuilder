<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Stats;

/**
 * Pure bucket math. Takes values plus the FROZEN series step only, never the field,
 * so later changes to field settings cannot move a removal away from its addition.
 */
class Bucket
{
    public const DAY = 0;
    public const MONTH = 1;
    public const YEAR = 12;

    private const DATE_PATTERN = '/^(\d{4})-(\d{2})(?:-(\d{2})(?:T\d{2}:\d{2})?)?$/D';

    /** Canonical string for numbers and bucket lower bounds. */
    public function format(int|float|string $value): string
    {
        $text = rtrim(rtrim(sprintf('%.6F', (float)$value), '0'), '.');
        return ($text === '-0' || $text === '') ? '0' : $text;
    }

    /** @param int $step DAY ('YYYY-MM-DD', or 'YYYY-MM' for a month answer), MONTH ('YYYY-MM'), YEAR ('YYYY') */
    public function date(string $value, int $step): ?string
    {
        if (!preg_match(self::DATE_PATTERN, $value, $m)) {
            return null;
        }
        return match (true) {
            $step === self::YEAR => $m[1],
            $step === self::DAY && isset($m[3]) => $m[1] . '-' . $m[2] . '-' . $m[3],
            default => $m[1] . '-' . $m[2],
        };
    }

    /** @param float|null $step null = floored to two significant figures, which nest in any 1/2/5 x 10^n bin */
    public function number(int|float|string $value, ?float $step): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float)$value;
        $step ??= $this->significantWidth($number);
        return $step > 0 ? $this->format(floor(round($number / $step, 9)) * $step) : $this->format(0);
    }

    /** Width of a two significant figure bucket at this magnitude; 0 for zero. */
    public function significantWidth(float $number): float
    {
        $abs = abs($number);
        if ($abs < 1e-6) {
            return 0.0;
        }
        $exponent = (int)floor(log10($abs));
        if (10 ** $exponent > $abs) {
            $exponent--;
        }
        return (float)(10 ** ($exponent - 1));
    }
}
