<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\FreeTextTypeInterface;
use Furan\FormBuilder\Api\FieldType\BuilderControlsInterface;

class Suggest extends AbstractFieldType implements FreeTextTypeInterface, BuilderControlsInterface
{
    public function getCode(): string
    {
        return 'suggest';
    }

    public function getLabel(): string
    {
        return (string)__('Suggest');
    }

    public function hasOptions(): bool
    {
        return true;
    }

    public function getAllowedRules(): array
    {
        return ['min_length', 'max_length', 'pattern', 'preset', 'alpha', 'alphanumeric', 'matches'];
    }

    public function normalizeValue(mixed $raw, array $field): string|array|null
    {
        $text = parent::normalizeValue($raw, $field);
        return is_string($text) ? SuggestionMatcher::match($text, (array)($field['options'] ?? [])) : $text;
    }

    public function isFreeText(): bool
    {
        return true;
    }

    public function builderControls(): array
    {
        return ['option_description' => false];
    }
}
