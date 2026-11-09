<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\RelativeDateSourceInterface;
use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Model\Validation\Patterns;

class DateTime extends AbstractPatternType implements StatsAwareTypeInterface, RelativeDateSourceInterface
{
    public function getCode(): string
    {
        return 'datetime';
    }

    public function getLabel(): string
    {
        return (string)__('Date and time');
    }

    public function getAllowedRules(): array
    {
        return ['min_date', 'max_date'];
    }
    /**
     * @param array<string, mixed> $field
     */
    public function getInvalidMessage(array $field): ?string
    {
        return (string)__('Please enter a valid date and time.');
    }

    protected function getPattern(): string
    {
        return Patterns::DATETIME;
    }
    protected function isFormatValid(string $value): bool
    {
        return parent::isFormatValid($value)
            && checkdate((int)substr($value, 5, 2), (int)substr($value, 8, 2), (int)substr($value, 0, 4));
    }

    public function statsKind(array $field): ?int
    {
        return self::KIND_DATE;
    }

    public function relativeDateFormat(): string
    {
        return 'Y-m-d';
    }
}
