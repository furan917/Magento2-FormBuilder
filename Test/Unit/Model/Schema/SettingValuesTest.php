<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Schema;

use Furan\FormBuilder\Model\Schema\SettingValues;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use PHPUnit\Framework\TestCase;

class SettingValuesTest extends TestCase
{
    /** @return array<int, array{0: string, 1: string, 2: mixed}> */
    private function problems(array $raw, array $spec): array
    {
        $problems = [];
        (new SettingValues((new \ReflectionClass(RedirectValidator::class))->newInstanceWithoutConstructor()))
            ->normalize($raw, $spec, $problems);
        return $problems;
    }

    public function testItReportsASettingAboveTheSiblingItMayNotExceed(): void
    {
        $spec = ['low' => ['type' => 'number', 'default' => 0, 'not_above' => 'high'], 'high' => ['type' => 'number', 'default' => 10]];

        $this->assertSame([[SettingValues::PROBLEM_ABOVE, 'low', 'high']], $this->problems(['low' => 11, 'high' => 10], $spec));
        $this->assertSame([], $this->problems(['low' => 10, 'high' => 10], $spec));
    }

    public function testItSkipsTheComparisonWhenTheSiblingHoldsItsNoLimitValue(): void
    {
        $spec = [
            'min_days' => ['type' => 'int', 'default' => 0, 'not_above' => 'max_days'],
            'max_days' => ['type' => 'int', 'default' => 0, 'no_limit' => 0],
        ];

        $this->assertSame([], $this->problems(['min_days' => 5, 'max_days' => 0], $spec));
        $this->assertSame([[SettingValues::PROBLEM_ABOVE, 'min_days', 'max_days']], $this->problems(['min_days' => 5, 'max_days' => 3], $spec));
    }

    public function testItComparesDatesAndSkipsEmptyOnes(): void
    {
        $date = ['type' => 'string', 'default' => '', 'format' => 'date'];
        $spec = ['from' => $date + ['not_above' => 'to'], 'to' => $date];

        $this->assertSame([[SettingValues::PROBLEM_ABOVE, 'from', 'to']], $this->problems(['from' => '2025-02-01', 'to' => '2025-01-31'], $spec));
        $this->assertSame([], $this->problems(['from' => '2025-02-01', 'to' => ''], $spec));
    }

    public function testItReportsAnInvalidDate(): void
    {
        $spec = ['from' => ['type' => 'string', 'default' => '', 'format' => 'date']];

        $this->assertSame([[SettingValues::PROBLEM_DATE, 'from', null]], $this->problems(['from' => '2025-02-30'], $spec));
        $this->assertSame([], $this->problems(['from' => '0099-01-01'], $spec));
    }

    public function testItReportsANumberNotAboveItsExclusiveMinimum(): void
    {
        $spec = ['step' => ['type' => 'number', 'default' => 1, 'exclusive_min' => 0]];

        $this->assertSame([[SettingValues::PROBLEM_EXCLUSIVE_MIN, 'step', 0]], $this->problems(['step' => 0], $spec));
        $this->assertSame([], $this->problems(['step' => 0.5], $spec));
    }

    public function testItReportsAStepThatIsNeitherAnyNorAPositiveNumber(): void
    {
        $spec = ['step' => ['type' => 'string', 'default' => 'any', 'format' => 'step']];

        foreach (['1,5', '0', '-1', '0.0'] as $step) {
            $this->assertSame([[SettingValues::PROBLEM_STEP, 'step', null]], $this->problems(['step' => $step], $spec), $step);
        }
        foreach (['', 'any', '2', '0.25'] as $step) {
            $this->assertSame([], $this->problems(['step' => $step], $spec), $step);
        }
    }
}
