<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface as Kind;
use Furan\FormBuilder\Model\Submission\AnswerDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnswerDateTest extends TestCase
{
    private function text(?string $kind, mixed $value, string $locale = 'en_GB'): ?string
    {
        return (new AnswerDate())->text($kind, $value, $locale);
    }

    /** @return array<string, array{0: string, 1: mixed, 2: string}> */
    public static function formatted(): array
    {
        return [
            'date' => [Kind::KIND_CALENDAR_DATE, '2026-10-02', '2 Oct 2026'],
            'datetime' => [Kind::KIND_CALENDAR_DATETIME, '2026-10-05T09:15', '5 Oct 2026, 09:15'],
            'month' => [Kind::KIND_CALENDAR_MONTH, '2026-09', 'September 2026'],
            'time' => [Kind::KIND_CALENDAR_TIME, '09:15', '09:15'],
            'date range' => [Kind::KIND_CALENDAR_RANGE, ['start' => '2026-12-20', 'end' => '2026-12-28'], "20 Dec 2026 \u{2013} 28 Dec 2026"],
        ];
    }

    #[DataProvider('formatted')]
    public function testItFormatsDateAnswersInTheGivenLocale(string $kind, mixed $value, string $expected): void
    {
        $this->assertSame($expected, $this->text($kind, $value));
    }

    public function testItKeepsTheWallClockTimeTheVisitorTypedWhateverTheServerTimezone(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati');
        try {
            $this->assertSame('5 Oct 2026, 23:45', $this->text(Kind::KIND_CALENDAR_DATETIME, '2026-10-05T23:45'));
            $this->assertSame('31 Dec 2026', $this->text(Kind::KIND_CALENDAR_DATE, '2026-12-31'));
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function testItFollowsTheLocale(): void
    {
        $text = (string)$this->text(Kind::KIND_CALENDAR_TIME, '21:05', 'en_US');
        $this->assertMatchesRegularExpression('/^9:05\s?PM$/u', $text);
        $this->assertSame('Oct 2, 2026', $this->text(Kind::KIND_CALENDAR_DATE, '2026-10-02', 'en_US'));
    }

    public function testItFormatsEachAnswerInItsOwnLocaleWithinOneProcess(): void
    {
        $dates = new AnswerDate();

        $this->assertSame('5 oct. 2026, 09:15', $dates->text(Kind::KIND_CALENDAR_DATETIME, '2026-10-05T09:15', 'fr_FR'));
        $this->assertSame('5 Oct 2026, 09:15', $dates->text(Kind::KIND_CALENDAR_DATETIME, '2026-10-05T09:15', 'en_GB'));
        $this->assertSame('septembre 2026', $dates->text(Kind::KIND_CALENDAR_MONTH, '2026-09', 'fr_FR'));
        $this->assertSame('21:05', $dates->text(Kind::KIND_CALENDAR_TIME, '21:05', 'de_DE'));
        $this->assertSame("2 oct. 2026 \u{2013} 4 oct. 2026", $dates->text(Kind::KIND_CALENDAR_RANGE, ['start' => '2026-10-02', 'end' => '2026-10-04'], 'fr_FR'));
    }

    /** @return array<string, array{0: ?string, 1: mixed}> */
    public static function untouched(): array
    {
        return [
            'not a date kind' => [Kind::KIND_CHIPS, '2026-10-02'],
            'plain layout' => [null, '2026-10-02'],
            'impossible date' => [Kind::KIND_CALENDAR_DATE, '2026-02-30'],
            'wrong shape' => [Kind::KIND_CALENDAR_DATE, '02/10/2026'],
            'datetime with seconds' => [Kind::KIND_CALENDAR_DATETIME, '2026-10-05T09:15:00'],
            'month 13' => [Kind::KIND_CALENDAR_MONTH, '2026-13'],
            'time 24:00' => [Kind::KIND_CALENDAR_TIME, '24:00'],
            'array for a single date' => [Kind::KIND_CALENDAR_DATE, ['2026-10-02']],
            'range missing its end' => [Kind::KIND_CALENDAR_RANGE, ['start' => '2026-12-20', 'end' => '']],
            'range as a string' => [Kind::KIND_CALENDAR_RANGE, '2026-12-20'],
            'empty' => [Kind::KIND_CALENDAR_DATE, ''],
        ];
    }

    #[DataProvider('untouched')]
    public function testItLeavesAnythingThatIsNotAStoredDateOfThatKindToThePlainLayout(?string $kind, mixed $value): void
    {
        $this->assertNull($this->text($kind, $value));
    }
}
