<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Schema;

use Furan\FormBuilder\Api\FieldType\PersonalByDefaultInterface;
use Furan\FormBuilder\Model\FieldType\Pool;

/**
 * The only way to read a field's personal-data flag: stored forms may lack the `personal` key.
 */
class PersonalData
{
    public const PERSONAL_AUTOCOMPLETE = [
        'name', 'given-name', 'family-name', 'email', 'tel', 'street-address',
        'address-line1', 'address-line2', 'postal-code', 'bday',
    ];

    public function __construct(private readonly Pool $fieldTypes)
    {
    }

    /** @param array<string, mixed> $field */
    public function isPersonal(array $field): bool
    {
        if (is_bool($field['personal'] ?? null)) {
            return $field['personal'];
        }
        return $this->isPersonalByDefault((string)($field['type'] ?? ''))
            || in_array($field['autocomplete'] ?? '', self::PERSONAL_AUTOCOMPLETE, true);
    }

    /**
     * For data leaving the store: an explicit `personal: false` cannot hide a personal type, autocomplete or stored type.
     *
     * @param array<string, mixed> $field
     */
    public function isPersonalForSharing(array $field, string $storedType = ''): bool
    {
        return ($field['personal'] ?? null) === true
            || $this->isPersonalByDefault((string)($field['type'] ?? ''))
            || ($storedType !== '' && $this->isPersonalByDefault($storedType))
            || in_array($field['autocomplete'] ?? '', self::PERSONAL_AUTOCOMPLETE, true);
    }

    private function isPersonalByDefault(string $code): bool
    {
        $type = $this->fieldTypes->has($code) ? $this->fieldTypes->get($code) : null;
        return $type instanceof PersonalByDefaultInterface && $type->isPersonalByDefault();
    }
}
