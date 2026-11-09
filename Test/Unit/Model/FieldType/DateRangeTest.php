<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Model\FieldType\DateRange;
use PHPUnit\Framework\TestCase;

class DateRangeTest extends TestCase
{
    private function field(array $settings = []): array
    {
        return ['required' => false, 'settings' => $settings];
    }

    private function check(array $value, array $settings = []): ?string
    {
        return (new DateRange())->validateValue($value, $this->field($settings), []);
    }

    public function testItNormalisesAPostedStartAndEndIntoAnOrderedPair(): void
    {
        $type = new DateRange();
        $this->assertSame(
            ['start' => '2026-01-02', 'end' => '2026-01-09'],
            $type->normalizeValue(['end' => ' 2026-01-09 ', 'start' => '2026-01-02', 'x' => 'y'], [])
        );
        $this->assertNull($type->normalizeValue(['start' => ' ', 'end' => ''], []));
        $this->assertNull($type->normalizeValue('2026-01-02', []));
        $this->assertSame(['start' => '2026-01-02', 'end' => ''], $type->normalizeValue(['start' => '2026-01-02'], []));
        $this->assertNull($type->validateValue(['start' => '2026-01-02', 'end' => '2026-01-02'], [], []));
        $this->assertNotNull($this->check(['start' => '2026-01-02', 'end' => '']));
        $this->assertNotNull($this->check(['start' => '2026-02-30', 'end' => '2026-03-01']));
    }

    public function testItRejectsAnEndDateBeforeTheStartDate(): void
    {
        $this->assertSame(
            (string)__('The end date must be on or after the start date.'),
            $this->check(['start' => '2026-01-09', 'end' => '2026-01-08'])
        );
    }

    public function testItRejectsARangeShorterThanTheMinimumLength(): void
    {
        $settings = ['min_days' => 3];
        $this->assertNotNull($this->check(['start' => '2026-01-01', 'end' => '2026-01-02'], $settings));
        $this->assertNull($this->check(['start' => '2026-01-01', 'end' => '2026-01-03'], $settings));
    }

    public function testItRejectsARangeLongerThanTheMaximumLength(): void
    {
        $settings = ['max_days' => 1];
        $this->assertNull($this->check(['start' => '2026-01-01', 'end' => '2026-01-01'], $settings));
        $this->assertNotNull($this->check(['start' => '2026-01-01', 'end' => '2026-01-02'], $settings));
        $this->assertNull($this->check(['start' => '2026-01-01', 'end' => '2026-03-01'], ['min_days' => 5, 'max_days' => 2]));
    }

    public function testItRejectsDatesOutsideTheEarliestAndLatestSettings(): void
    {
        $settings = ['min_date' => '2026-02-01', 'max_date' => '2026-02-28'];
        $this->assertNotNull($this->check(['start' => '2026-01-31', 'end' => '2026-02-02'], $settings));
        $this->assertNotNull($this->check(['start' => '2026-02-10', 'end' => '2026-03-01'], $settings));
        $this->assertNull($this->check(['start' => '2026-02-01', 'end' => '2026-02-28'], $settings));
    }

    public function testItMarksStartAndEndLabelsAsTranslatableSettings(): void
    {
        $spec = (new DateRange())->getSettingsSpec();
        foreach (['start_label', 'end_label'] as $key) {
            $this->assertSame('string', $spec[$key]['type']);
            $this->assertTrue($spec[$key]['translatable']);
            $this->assertSame(60, $spec[$key]['max_length']);
            $this->assertSame('', $spec[$key]['default']);
        }
        $this->assertSame(3660, $spec['min_days']['max']);
        $this->assertSame([], (new DateRange())->getAllowedRules());
    }

    public function testItIgnoresEarliestAndLatestSettingsThatAreNotRealDates(): void
    {
        $settings = ['min_date' => '2026-13-45', 'max_date' => 'tomorrow'];
        $this->assertNull($this->check(['start' => '1999-01-01', 'end' => '2099-01-01'], $settings));
    }

    public function testItReturnsTranslatedClientMessagesForEachDateRangeError(): void
    {
        $messages = (new DateRange())->getClientMessages($this->field([
            'min_days' => 2, 'max_days' => 9, 'min_date' => '2026-02-01', 'max_date' => '2026-02-28',
        ]))['messages'];
        $this->assertSame(['order', 'incomplete', 'min_days', 'max_days', 'min_date', 'max_date'], array_keys($messages));
        $this->assertSame(
            $this->check(['start' => '2026-02-10', 'end' => '2026-02-10'], ['min_days' => 2]),
            $messages['min_days']
        );
        $this->assertStringContainsString('9', $messages['max_days']);
        $this->assertStringContainsString('2026-02-01', $messages['min_date']);
        $this->assertStringContainsString('2026-02-28', $messages['max_date']);
    }
}
