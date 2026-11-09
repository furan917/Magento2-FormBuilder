<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\AdminViewTypeInterface;
use Furan\FormBuilder\Api\FieldType\FreeTextTypeInterface;
class Suggest extends AbstractFieldType implements FreeTextTypeInterface, AdminViewTypeInterface
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

    public function isFreeText(): bool
    {
        return true;
    }

    public function adminViewKind(array $field): ?string
    {
        return self::KIND_FREE_TEXT;
    }
}
