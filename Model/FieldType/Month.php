<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface;
use Furan\FormBuilder\Api\FieldType\RelativeDateSourceInterface;
use Furan\FormBuilder\Api\FieldType\StatsAwareTypeInterface;
use Furan\FormBuilder\Model\Validation\Patterns;
use Furan\FormBuilder\Api\FieldType\BuilderControlsInterface;

class Month extends AbstractPatternType implements StatsAwareTypeInterface, RelativeDateSourceInterface, AdminViewTypeInterface, BuilderControlsInterface
{
    public function getCode(): string
    {
        return 'month';
    }

    public function getLabel(): string
    {
        return (string)__('Month');
    }

    /**
     * @param array<string, mixed> $field
     */
    public function getInvalidMessage(array $field): ?string
    {
        return (string)__('Please enter a valid month.');
    }

    protected function getPattern(): string
    {
        return Patterns::MONTH;
    }

    public function statsKind(array $field): ?int
    {
        return self::KIND_DATE;
    }

    public function relativeDateFormat(): string
    {
        return 'Y-m';
    }

    public function adminViewKind(array $field): ?string
    {
        return AdminViewTypeInterface::KIND_CALENDAR_MONTH;
    }

    public function builderControls(): array
    {
        return ['placeholder' => false];
    }

    public function defaultInput(): ?string
    {
        return 'month';
    }
}
