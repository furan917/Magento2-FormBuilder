<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Schema;

use Furan\FormBuilder\Model\Url\RedirectValidator;
use Furan\FormBuilder\Model\Validation\Mask;

/**
 * Normalises values against a settings spec; shared by field settings and form settings contributors.
 *
 * @phpstan-import-type SettingSpec from \Furan\FormBuilder\Api\FieldTypeInterface
 */
class SettingValues
{
    public const PROBLEM_MIN_ITEMS = 'min_items';
    public const PROBLEM_URL = 'url';
    public const PROBLEM_MASK = 'mask';
    public const PROBLEM_ABOVE = 'above';
    public const PROBLEM_EXCLUSIVE_MIN = 'exclusive_min';
    public const PROBLEM_DATE = 'date';
    public const PROBLEM_STEP = 'step';

    private const LIST_LIMIT = 50;

    public function __construct(private readonly RedirectValidator $redirectValidator)
    {
    }

    /**
     * @param array<string, SettingSpec> $spec
     * @param array<int, array{0: string, 1: string, 2: mixed}> $problems appended as [kind, key, argument]
     * @return array<string, mixed> one entry per spec key
     * @param array<string, mixed> $raw
     */
    public function normalize(array $raw, array $spec, array &$problems): array
    {
        $values = [];
        foreach ($spec as $key => $definition) {
            $values[$key] = ($definition['format'] ?? '') === 'mask'
                ? $this->mask($raw[$key] ?? null, (string)$key, $problems)
                : $this->coerce($raw[$key] ?? null, $definition);
            if (($definition['type'] ?? '') === 'list' && count($values[$key]) < ($definition['min_items'] ?? 0)) {
                $problems[] = [self::PROBLEM_MIN_ITEMS, (string)$key, $definition['min_items']];
            }
            if (($definition['format'] ?? '') === 'url' && $values[$key] !== ''
                && !$this->redirectValidator->isAllowed($values[$key])
            ) {
                $problems[] = [self::PROBLEM_URL, (string)$key, null];
                $values[$key] = '';
            }
            $this->checkValue($values[$key], (string)$key, $definition, $problems);
        }
        foreach ($spec as $key => $definition) {
            $sibling = $definition['not_above'] ?? null;
            if (is_string($sibling) && isset($spec[$sibling]) && $this->isAbove($values[$key], $values[$sibling], $spec[$sibling])) {
                $problems[] = [self::PROBLEM_ABOVE, (string)$key, $sibling];
            }
        }
        return $values;
    }

    /**
     * @param SettingSpec $spec
     * @param array<int, array{0: string, 1: string, 2: mixed}> $problems
     */
    private function checkValue(mixed $value, string $key, array $spec, array &$problems): void
    {
        $format = $spec['format'] ?? '';
        if ($format === 'date' && is_string($value) && $value !== '' && !$this->isDate($value)) {
            $problems[] = [self::PROBLEM_DATE, $key, null];
        }
        if ($format === 'step' && is_string($value) && !in_array($value, ['', 'any'], true)
            && !(preg_match('/^\d+(\.\d+)?$/D', $value) === 1 && (float)$value > 0)
        ) {
            $problems[] = [self::PROBLEM_STEP, $key, null];
        }
        if (isset($spec['exclusive_min']) && is_numeric($value) && $value <= $spec['exclusive_min']) {
            $problems[] = [self::PROBLEM_EXCLUSIVE_MIN, $key, $spec['exclusive_min']];
        }
    }

    /**
     * Empty values, invalid dates and a sibling at its `no_limit` value are never compared.
     *
     * @param SettingSpec $siblingSpec
     */
    private function isAbove(mixed $value, mixed $sibling, array $siblingSpec): bool
    {
        if (array_key_exists('no_limit', $siblingSpec) && $sibling == $siblingSpec['no_limit']) {
            return false;
        }
        if (is_numeric($value) && is_numeric($sibling)) {
            return (float)$value > (float)$sibling;
        }
        return is_string($value) && is_string($sibling) && $this->isDate($value) && $this->isDate($sibling) && $value > $sibling;
    }

    private function isDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) === 1 && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    public function cleanString(mixed $value, int $maxLength, bool $keepNewlines = false): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $string = (string)$value;
        if ($keepNewlines) {
            $string = str_replace(["\r\n", "\r"], "\n", $string);
        }
        $string = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $string) ?? '';
        if (!$keepNewlines) {
            $string = str_replace("\n", ' ', $string);
        }
        return mb_substr(trim($string), 0, $maxLength);
    }

    public function toBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $parsed = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        return $parsed ?? $default;
    }

    /**
     * Checked on the posted text itself: trimming or cutting it first could turn a bad mask into a good one.
     *
     * @param array<int, array{0: string, 1: string, 2: mixed}> $problems
     */
    private function mask(mixed $raw, string $key, array &$problems): string
    {
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        if (Mask::isValid($raw)) {
            return $raw;
        }
        $problems[] = [self::PROBLEM_MASK, $key, Mask::MAX_LENGTH];
        return '';
    }

    /**
     * @param SettingSpec $spec
     */
    private function coerce(mixed $value, array $spec): mixed
    {
        $default = $spec['default'] ?? null;
        switch ($spec['type'] ?? 'string') {
            case 'int':
                if (!is_numeric($value)) {
                    return $default;
                }
                return $this->clamp((int)$value, $spec);
            case 'number':
                if (!is_numeric($value) || !is_finite((float)$value)) {
                    return $default;
                }
                $number = $this->clamp((float)$value, $spec);
                return floor($number) === $number && abs($number) < 1e15 ? (int)$number : $number;
            case 'bool':
                return $this->toBool($value, (bool)$default);
            case 'enum':
                return is_string($value) && in_array($value, $spec['values'] ?? [], true) ? $value : $default;
            case 'list':
                if (!is_array($value)) {
                    return $default ?? [];
                }
                $list = [];
                foreach ($value as $entry) {
                    $entry = is_string($entry) ? $this->cleanString($entry, 255) : '';
                    if ($entry !== '' && count($list) < ($spec['max_items'] ?? self::LIST_LIMIT)) {
                        $list[] = $entry;
                    }
                }
                return $list;
            default:
                return is_scalar($value) ? $this->cleanString($value, $spec['max_length'] ?? 255) : $default;
        }
    }

    /**
     * @param SettingSpec $spec
     */
    private function clamp(int|float $number, array $spec): int|float
    {
        if (isset($spec['min'])) {
            $number = max($spec['min'], $number);
        }
        if (isset($spec['max'])) {
            $number = min($spec['max'], $number);
        }
        return $number;
    }
}
