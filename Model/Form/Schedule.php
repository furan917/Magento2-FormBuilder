<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Form;

/**
 * Open/close instants of a form. Settings hold UTC ISO instants ("2026-07-01T09:00:00Z"); the builder posts
 * either such an instant (unedited) or a naive "YYYY-MM-DDTHH:MM" meaning wall-clock time in the chosen zone.
 */
class Schedule
{
    public const KEYS = ['open_from', 'close_at'];
    public const OPEN = 'open';
    public const NOT_YET = 'not_yet';
    public const CLOSED = 'closed';
    private const INSTANT = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?(Z|[+-]\d{2}:\d{2})$/D';
    private const LOCAL = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/D';

    /** @var array<string, int>|null */
    private static ?array $identifiers = null;

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $stored
     * @param string[] $errors
     * @return array{open_from: string, close_at: string, schedule_timezone: string}
     */
    public static function normalize(array $raw, array $stored, string $defaultZone, array &$errors): array
    {
        $out = ['open_from' => '', 'close_at' => '', 'schedule_timezone' => ''];
        $storedZone = is_string($stored['schedule_timezone'] ?? null) ? $stored['schedule_timezone'] : '';
        $posted = is_string($raw['schedule_timezone'] ?? null) ? trim($raw['schedule_timezone']) : '';
        if ($posted !== '' && !self::isAcceptedZone($posted, $defaultZone, $storedZone)) {
            $errors[] = (string)__('The schedule timezone is not a valid timezone.');
            return $out;
        }
        $zoneName = $posted !== '' ? $posted : ($storedZone !== '' ? $storedZone : ($defaultZone !== '' ? $defaultZone : 'UTC'));
        $zone = self::zone($zoneName);
        if ($zone === null) {
            $errors[] = (string)__('The schedule timezone is not a valid timezone.');
            return $out;
        }

        $instants = [];
        foreach (self::KEYS as $key) {
            $text = is_string($raw[$key] ?? null) ? trim($raw[$key]) : '';
            if ($text === '') {
                continue;
            }
            $instant = self::parse($text, $zone);
            if ($instant === null) {
                $errors[] = $key === 'open_from'
                    ? (string)__('The open time is not a valid date and time.')
                    : (string)__('The close time is not a valid date and time.');
                continue;
            }
            $instants[$key] = $instant;
            $out[$key] = $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        if (isset($instants['open_from'], $instants['close_at']) && $instants['close_at'] <= $instants['open_from']) {
            $errors[] = (string)__('The close time must be after the open time.');
        }
        if ($out['open_from'] !== '' || $out['close_at'] !== '') {
            $out['schedule_timezone'] = $zoneName;
        }
        return $out;
    }

    /** open | not_yet | closed at the UTC unix time $now; unset or unreadable times do not restrict. */
    public static function state(mixed $openFrom, mixed $closeAt, int $now): string
    {
        $open = self::timestamp($openFrom);
        $close = self::timestamp($closeAt);
        if ($open !== null && $now < $open) {
            return self::NOT_YET;
        }
        return $close !== null && $now >= $close ? self::CLOSED : self::OPEN;
    }

    private static function timestamp(mixed $value): ?int
    {
        $instant = is_string($value) ? self::parse($value, null) : null;
        return $instant?->getTimestamp();
    }

    /** UTC `Y-m-d H:i:s` for the indexed columns; null for '' or anything that is not a stored instant. */
    public static function toSql(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $instant = self::parse($value, null);
        return $instant?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** `Y-m-d\TH:i` wall-clock time for a datetime-local input; a naive value (failed save) is returned as typed. */
    public static function toLocal(mixed $value, string $zoneName): string
    {
        if (!is_string($value)) {
            return '';
        }
        if (preg_match(self::LOCAL, $value) === 1) {
            return $value;
        }
        $zone = self::zone($zoneName === '' ? 'UTC' : $zoneName);
        $instant = $zone === null ? null : self::parse($value, null);
        return $instant === null || $zone === null ? '' : $instant->setTimezone($zone)->format('Y-m-d\TH:i');
    }

    /** Exact IANA ids only: `new DateTimeZone()` alone would accept offsets and abbreviations. */
    public static function isIdentifier(string $zone): bool
    {
        self::$identifiers ??= array_flip(\DateTimeZone::listIdentifiers());
        return isset(self::$identifiers[$zone]);
    }

    private static function isAcceptedZone(string $zone, string $defaultZone, string $storedZone): bool
    {
        return (self::isIdentifier($zone) || $zone === $defaultZone || $zone === $storedZone) && self::zone($zone) !== null;
    }

    private static function zone(string $name): ?\DateTimeZone
    {
        try {
            return new \DateTimeZone($name);
        } catch (\Exception) {
            return null;
        }
    }

    /** An ISO instant, or a naive local time in $zone (null zone = naive not accepted). */
    private static function parse(string $text, ?\DateTimeZone $zone): ?\DateTimeImmutable
    {
        if (preg_match(self::LOCAL, $text, $m) === 1) {
            return $zone === null ? null : self::build($m, 0, null, $zone);
        }
        if (preg_match(self::INSTANT, $text, $m) !== 1) {
            return null;
        }
        $offset = $m[7];
        if ($offset !== 'Z' && (int)substr($offset, 1, 2) > 23) {
            return null;
        }
        return self::build($m, (int)$m[6], $offset, null);
    }

    /**
     * @param array<int, string> $m date and time parts from the matched pattern (1 = year ... 5 = minute)
     */
    private static function build(array $m, int $second, ?string $offset, ?\DateTimeZone $zone): ?\DateTimeImmutable
    {
        [$year, $month, $day, $hour, $minute] = [(int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4], (int)$m[5]];
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }
        $iso = sprintf('%04d-%02d-%02dT%02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second) . ($offset ?? '');
        try {
            return new \DateTimeImmutable($iso, $zone);
        } catch (\Exception) {
            return null;
        }
    }
}
