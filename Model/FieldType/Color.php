<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface;
use Furan\FormBuilder\Model\Validation\Patterns;

class Color extends AbstractPatternType implements AdminViewTypeInterface
{
    public function getCode(): string
    {
        return 'color';
    }

    public function getLabel(): string
    {
        return (string)__('Colour');
    }

    /**
     * @param array<string, mixed> $field
     */
    public function getInvalidMessage(array $field): ?string
    {
        return (string)__('Please choose a valid colour.');
    }

    protected function getPattern(): string
    {
        return Patterns::COLOR;
    }
    protected function transform(string $value): string
    {
        return strtolower($value);
    }

    public function adminViewKind(array $field): ?string
    {
        return self::KIND_COLOR;
    }
}
