<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Model\Validation\Patterns;

class Time extends AbstractPatternType
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
    }}
