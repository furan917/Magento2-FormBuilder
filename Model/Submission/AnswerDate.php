<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface as Kind;

/**
 * Date answers in a given locale; wall-clock values, so read and formatted in UTC.
 */
class AnswerDate
{
    private const FORMATS = [
        Kind::KIND_CALENDAR_DATE => '!Y-m-d',
        Kind::KIND_CALENDAR_DATETIME => '!Y-m-d\TH:i',
        Kind::KIND_CALENDAR_MONTH => '!Y-m',
        Kind::KIND_CALENDAR_TIME => '!H:i',
    ];

    /** @var array<string, \IntlDateFormatter> */
    private array $formatters = [];

    /** Null when the kind is not a date kind or the value is not a valid stored answer of it. */
    public function text(?string $kind, mixed $value, string $locale): ?string
    {
        if ($kind === Kind::KIND_CALENDAR_RANGE) {
            $start = is_array($value) ? $this->text(Kind::KIND_CALENDAR_DATE, $value['start'] ?? null, $locale) : null;
            $end = is_array($value) ? $this->text(Kind::KIND_CALENDAR_DATE, $value['end'] ?? null, $locale) : null;
            return $start === null || $end === null ? null : $start . " \u{2013} " . $end;
        }
        $format = $kind === null ? null : self::FORMATS[$kind] ?? null;
        if ($kind === null || $format === null || !is_string($value)) {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        $date = \DateTimeImmutable::createFromFormat($format, $value, $utc);
        // Rejects overflow such as 2026-02-30 or 24:00, which createFromFormat rolls over.
        if ($date === false || $date->format(substr($format, 1)) !== $value) {
            return null;
        }
        $formatted = ($this->formatters[$kind . '|' . $locale] ??= $this->formatter($kind, $locale, $utc))->format($date);
        return is_string($formatted) && $formatted !== '' ? $formatted : null;
    }

    private function formatter(string $kind, string $locale, \DateTimeZone $utc): \IntlDateFormatter
    {
        if ($kind === Kind::KIND_CALENDAR_MONTH) {
            $pattern = (string)(new \IntlDatePatternGenerator($locale))->getBestPattern('MMMMy');
            return new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $utc, null, $pattern);
        }
        return new \IntlDateFormatter(
            $locale,
            $kind === Kind::KIND_CALENDAR_TIME ? \IntlDateFormatter::NONE : \IntlDateFormatter::MEDIUM,
            $kind === Kind::KIND_CALENDAR_DATE ? \IntlDateFormatter::NONE : \IntlDateFormatter::SHORT,
            $utc,
            \IntlDateFormatter::GREGORIAN
        );
    }
}
