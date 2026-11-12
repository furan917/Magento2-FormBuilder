<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface;
use Furan\FormBuilder\Model\Validation\Patterns;
use Furan\FormBuilder\Api\FieldType\BuilderControlsInterface;

class Time extends AbstractPatternType implements AdminViewTypeInterface, BuilderControlsInterface
{
    public function getCode(): string
    {
        return 'time';
    }

    public function getLabel(): string
    {
        return (string)__('Time');
    }

    /**
     * @param array<string, mixed> $field
     */
    public function getInvalidMessage(array $field): ?string
    {
        return (string)__('Please enter a valid time.');
    }

    protected function getPattern(): string
    {
        return Patterns::TIME;
    }

    public function adminViewKind(array $field): ?string
    {
        return AdminViewTypeInterface::KIND_CALENDAR_TIME;
    }

    public function builderControls(): array
    {
        return ['placeholder' => false];
    }

    public function defaultInput(): ?string
    {
        return 'time';
    }
}
