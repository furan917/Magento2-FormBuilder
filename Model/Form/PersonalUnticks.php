<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Form;

use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Schema\SchemaFields;

/** Finds fields that were personal in the stored schema and are not in the new one. */
class PersonalUnticks
{
    public function __construct(private readonly PersonalData $personalData)
    {
    }

    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $new
     * @return array<int, array{name: string, label: string}>
     */
    public function find(array $stored, array $new): array
    {
        $byId = [];
        $byName = [];
        foreach (SchemaFields::all($new) as $field) {
            $byId[(string)($field['id'] ?? '')] = $field;
            $byName[(string)($field['name'] ?? '')] = $field;
        }
        $unticked = [];
        foreach (SchemaFields::all($stored) as $field) {
            $match = $byId[(string)($field['id'] ?? '')] ?? $byName[(string)($field['name'] ?? '')] ?? null;
            if ($match !== null && $this->personalData->isPersonal($field) && !$this->personalData->isPersonal($match)) {
                $name = (string)($field['name'] ?? '');
                $unticked[] = ['name' => $name, 'label' => (string)($field['label'] ?? '') ?: $name];
            }
        }
        return $unticked;
    }
}
