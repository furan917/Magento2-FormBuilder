<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldType;

use Furan\FormBuilder\Api\FieldType\AvailabilityInterface;
use Furan\FormBuilder\Api\FieldType\ConsentTypeInterface;
use Furan\FormBuilder\Api\FieldType\EmailAddressTypeInterface;
use Furan\FormBuilder\Api\FieldType\FixedValueTypeInterface;
use Furan\FormBuilder\Api\FieldType\ServerValueTypeInterface;
use Furan\FormBuilder\Api\FieldTypeInterface;

class Pool
{
    /**
     * @param FieldTypeInterface[] $types keyed by field type code
     */
    public function __construct(private readonly array $types = [])
    {
    }

    public function get(string $code): FieldTypeInterface
    {
        if (!isset($this->types[$code])) {
            throw new \InvalidArgumentException(sprintf('Unknown field type "%s".', $code));
        }
        return $this->types[$code];
    }

    public function has(string $code): bool
    {
        return isset($this->types[$code]);
    }

    /** Registered and, for a type with the availability capability, available (null store = module level). */
    public function isUsable(string $code, ?int $storeId = null): bool
    {
        if (!isset($this->types[$code])) {
            return false;
        }
        $type = $this->types[$code];
        return !$type instanceof AvailabilityInterface || $type->isAvailable($storeId);
    }

    /** False for a code that is not registered (for example a type whose module was disabled). */
    public function isFile(string $code): bool
    {
        return isset($this->types[$code]) && $this->types[$code]->isFile();
    }

    public function isConsent(string $code): bool
    {
        return ($this->types[$code] ?? null) instanceof ConsentTypeInterface;
    }

    public function isEmailAddress(string $code): bool
    {
        return ($this->types[$code] ?? null) instanceof EmailAddressTypeInterface;
    }

    /** `hidden` is always a server value, registered or not (fail closed). */
    public function isServerValue(string $code): bool
    {
        return $code === 'hidden' || ($this->types[$code] ?? null) instanceof ServerValueTypeInterface;
    }

    /**
     * @param array<string, mixed> $field
     */
    public function fixedValue(array $field): ?string
    {
        $type = $this->types[(string)($field['type'] ?? '')] ?? null;
        return $type instanceof FixedValueTypeInterface ? $type->fixedValue($field) : null;
    }

    /**
     * @return FieldTypeInterface[]
     */
    public function all(): array
    {
        return $this->types;
    }
}
