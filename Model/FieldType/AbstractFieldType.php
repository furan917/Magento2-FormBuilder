<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\DefaultValueInterface;
use Furan\FormBuilder\Api\FieldType\MaxLengthInterface;
use Furan\FormBuilder\Api\FieldTypeInterface;
use Furan\FormBuilder\Model\Validation\Mask;

abstract class AbstractFieldType implements FieldTypeInterface, MaxLengthInterface, DefaultValueInterface
{
    protected const MAX_LENGTH = 1000;

    public function getCategory(): string
    {
        return 'input';
    }

    public function getBuilderDefaults(): array
    {
        return [];
    }

    public function getAllowedRules(): array
    {
        return [];
    }

    public function getSettingsSpec(): array
    {
        return [];
    }

    public function hasOptions(): bool
    {
        return false;
    }

    public function isFile(): bool
    {
        return false;
    }

    public function isMultiValue(): bool
    {
        return false;
    }

    public function getTemplate(): string
    {
        return 'Furan_FormBuilder::item/' . $this->getCode() . '.phtml';
    }

    public function normalizeValue(mixed $raw, array $field): string|array|null
    {
        if (!is_scalar($raw)) {
            return null;
        }
        $value = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', (string)$raw) ?? '';
        if (!$this->keepsNewlines()) {
            $value = str_replace("\n", ' ', $value);
        }
        return mb_substr(trim($this->applyMask(trim($this->transform($value)), $field)), 0, $this->maxLength());
    }

    public function validateValue(string|array $value, array $field, array $context): ?string
    {
        if (is_string($value) && !$this->isFormatValid($value)) {
            return $this->getInvalidMessage($field);
        }
        return null;
    }

    public function getInvalidMessage(array $field): ?string
    {
        return null;
    }

    protected function isFormatValid(string $value): bool
    {
        return true;
    }

    /**
     * Settings-spec entry for the input mask; types that list it in getSettingsSpec() get masked values.
     *
     * @return array{type: string, default: string, format: string, max_length: int}
     */
    protected function maskSpec(): array
    {
        return ['type' => 'string', 'format' => 'mask', 'default' => '', 'max_length' => Mask::MAX_LENGTH];
    }

    /**
     * @param array<string, mixed> $field
     */
    private function applyMask(string $value, array $field): string
    {
        $mask = $field['settings']['mask'] ?? '';
        return is_string($mask) && $mask !== '' && isset($this->getSettingsSpec()['mask'])
            ? Mask::format($value, $mask)
            : $value;
    }

    protected function keepsNewlines(): bool
    {
        return false;
    }

    public function maxLength(): int
    {
        return static::MAX_LENGTH;
    }

    public function defaultInput(): ?string
    {
        return self::INPUT_TEXT;
    }

    public function defaultChoices(): ?array
    {
        return null;
    }

    public function isValidDefault(string $default, array $field): bool
    {
        return $this->validateValue($default, $field, ['values' => [], 'store_id' => 0]) === null;
    }

    protected function transform(string $value): string
    {
        return $value;
    }
}
