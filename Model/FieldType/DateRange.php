<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface;
use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Api\FieldType\ClientMessagesInterface;
use Furan\FormBuilder\Api\FieldType\ConditionSourceInterface;
use Furan\FormBuilder\Api\FieldType\DisplayableTypeInterface;
use Furan\FormBuilder\Api\FieldType\ExportableTypeInterface;
use Furan\FormBuilder\Api\FieldType\RoutableTypeInterface;
use Furan\FormBuilder\Model\Submission\ValueFormatter;
use Furan\FormBuilder\Api\Data\DisplayContext;
use Magento\Framework\Escaper;
use Furan\FormBuilder\Api\FieldType\BuilderControlsInterface;

/**
 * Start and end date posted as name[start], name[end]. Length is inclusive (same day = 1) and
 * computed from calendar dates only, so time zones and DST never change it.
 */
class DateRange extends AbstractFieldType implements
    ExportableTypeInterface,
    DisplayableTypeInterface,
    ClientMessagesInterface,
    ConditionSourceInterface,
    RoutableTypeInterface,
    StatsAwareTypeInterface,
    AdminViewTypeInterface,
    BuilderControlsInterface
{
    private const DATE_PATTERN = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D';
    private const MAX_PART_LENGTH = 32;
    private const MAX_DAYS = 3660;

    public function getCode(): string
    {
        return 'date_range';
    }

    public function canBeConditionSource(): bool
    {
        return false;
    }

    public function canBeRouted(): bool
    {
        return false;
    }

    public function getLabel(): string
    {
        return (string)__('Date range');
    }

    public function getSettingsSpec(): array
    {
        $label = ['type' => 'string', 'translatable' => true, 'max_length' => 60, 'default' => ''];
        $date = ['type' => 'string', 'format' => 'date', 'max_length' => 10, 'default' => ''];
        $days = [
            'type' => 'int',
            'default' => 0,
            'min' => 0,
            'max' => self::MAX_DAYS,
            'hint' => (string)__('Counts the start and end days. 0 = no limit.'),
            'no_limit' => 0,
        ];
        return [
            'min_days' => $days + ['not_above' => 'max_days'],
            'max_days' => $days,
            'min_date' => $date + ['not_above' => 'max_date'],
            'max_date' => $date,
            'start_label' => $label,
            'end_label' => $label,
        ];
    }

    public function normalizeValue(mixed $raw, array $field): string|array|null
    {
        if (!is_array($raw)) {
            return null;
        }
        $pair = ['start' => $this->cleanPart($raw['start'] ?? null), 'end' => $this->cleanPart($raw['end'] ?? null)];
        return $pair['start'] === '' && $pair['end'] === '' ? null : $pair;
    }

    public function validateValue(string|array $value, array $field, array $context): ?string
    {
        $messages = $this->messages($field);
        $start = is_array($value) ? (string)($value['start'] ?? '') : '';
        $end = is_array($value) ? (string)($value['end'] ?? '') : '';
        if ($start === '' || $end === '') {
            return $messages['incomplete'];
        }
        if (!$this->isRealDate($start) || !$this->isRealDate($end)) {
            return (string)__('Please enter a valid date.');
        }
        $settings = $this->getSettings($field);
        $length = $this->toDays($end) - $this->toDays($start) + 1;
        $failure = match (true) {
            $length < 1 => 'order',
            $settings['min_days'] > 0 && $length < $settings['min_days'] => 'min_days',
            $settings['max_days'] > 0 && $length > $settings['max_days'] => 'max_days',
            $settings['min_date'] !== '' && min($start, $end) < $settings['min_date'] => 'min_date',
            $settings['max_date'] !== '' && max($start, $end) > $settings['max_date'] => 'max_date',
            default => null,
        };
        return $failure === null ? null : $messages[$failure];
    }


    public function getClientMessages(array $field): array
    {
        return ['messages' => $this->messages($field)];
    }

    /**
     * @return array<string,string>
     * @param array<string, mixed> $field
     */
    private function messages(array $field): array
    {
        $settings = $this->getSettings($field);
        return [
            'order' => (string)__('The end date must be on or after the start date.'),
            'incomplete' => (string)__('Please enter both a start date and an end date.'),
            'min_days' => (string)__('Please choose a range of at least %1 days.', $settings['min_days']),
            'max_days' => (string)__('Please choose a range of no more than %1 days.', $settings['max_days']),
            'min_date' => (string)__('Please choose dates on or after %1.', $settings['min_date']),
            'max_date' => (string)__('Please choose dates on or before %1.', $settings['max_date']),
        ];
    }

    /**
     * @return array{min_days:int,max_days:int,min_date:string,max_date:string}
     * @param array<string, mixed> $field
     */
    private function getSettings(array $field): array
    {
        $raw = $field['settings'] ?? [];
        return [
            'min_days' => $this->clampDays($raw['min_days'] ?? 0),
            'max_days' => $this->clampDays($raw['max_days'] ?? 0),
            'min_date' => $this->cleanBound($raw['min_date'] ?? ''),
            'max_date' => $this->cleanBound($raw['max_date'] ?? ''),
        ];
    }

    private function clampDays(mixed $value): int
    {
        return is_numeric($value) ? max(0, min(self::MAX_DAYS, (int)$value)) : 0;
    }

    private function cleanBound(mixed $value): string
    {
        return is_string($value) && $this->isRealDate($value) ? $value : '';
    }

    private function cleanPart(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', (string)$value) ?? '';
        return mb_substr(trim($clean), 0, self::MAX_PART_LENGTH);
    }

    private function isRealDate(string $value): bool
    {
        return preg_match(self::DATE_PATTERN, $value) === 1
            && checkdate((int)substr($value, 5, 2), (int)substr($value, 8, 2), (int)substr($value, 0, 4));
    }

    /** Days since 1970-01-01 (Howard Hinnant's days_from_civil), mirrored in the JS. */
    private function toDays(string $date): int
    {
        $year = (int)substr($date, 0, 4);
        $month = (int)substr($date, 5, 2);
        $day = (int)substr($date, 8, 2);
        $year -= $month <= 2 ? 1 : 0;
        $era = intdiv($year >= 0 ? $year : $year - 399, 400);
        $yearOfEra = $year - $era * 400;
        $dayOfYear = intdiv(153 * ($month > 2 ? $month - 3 : $month + 9) + 2, 5) + $day - 1;
        $dayOfEra = $yearOfEra * 365 + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100) + $dayOfYear;
        return $era * 146097 + $dayOfEra - 719468;
    }

    public function csvHeaders(array $field): array
    {
        $label = (string)($field['label'] ?? '');
        return [$label . ' (start)', $label . ' (end)'];
    }

    public function csvCells(array $field, mixed $value): array
    {
        return [$this->part($value, 'start'), $this->part($value, 'end')];
    }

    public function csvPlainColumns(array $field): array
    {
        return [];
    }

    public function toText(array $field, mixed $value, DisplayContext $context): string
    {
        if (!is_array($value)) {
            return ValueFormatter::flatten($value);
        }
        $start = $this->part($value, 'start');
        $end = $this->part($value, 'end');
        return $start === '' && $end === '' ? '' : $start . " \u{2013} " . $end;
    }

    public function toHtml(array $field, mixed $value, Escaper $escaper, DisplayContext $context): string
    {
        return $escaper->escapeHtml($this->toText($field, $value, $context));
    }

    private function part(mixed $value, string $key): string
    {
        return is_array($value) && is_scalar($value[$key] ?? null) ? (string)$value[$key] : '';
    }

    public function statsKind(array $field): ?int
    {
        return self::KIND_DATE_RANGE;
    }

    public function adminViewKind(array $field): ?string
    {
        return AdminViewTypeInterface::KIND_CALENDAR_RANGE;
    }

    public function builderControls(): array
    {
        return ['placeholder' => false, 'autocomplete' => false];
    }

    public function defaultInput(): ?string
    {
        return null;
    }
}
