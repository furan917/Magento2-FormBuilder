<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldGroup;

use Furan\FormBuilder\Model\I18n\Translator;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\Normalizer;
use Furan\FormBuilder\Model\Schema\SchemaFields;

/**
 * Cleans the fields and translations of a field group with the rules of a form schema. The items are checked
 * inside one page that has no condition and no skip, so a rule can only name fields of the group itself.
 */
class GroupNormalizer
{
    public function __construct(private readonly Normalizer $normalizer, private readonly Translator $translator)
    {
    }

    /**
     * @param int[] $storeIds stores a translation may belong to
     * @return array{fields: list<array<string, mixed>>, translations: array<int, array<string, mixed>>}
     * @throws InvalidSchemaException
     */
    public function normalize(mixed $fields, mixed $translations, array $storeIds): array
    {
        if (!is_array($fields) || !array_is_list($fields) || $fields === []) {
            throw new InvalidSchemaException([(string)__('A group needs at least one field.')]);
        }
        $schema = SchemaFields::map(
            $this->normalizer->normalize(['pages' => [['items' => $fields]]]),
            static function (array $item): array {
                unset($item['source_group']);
                return $item;
            },
            ['section', 'field', 'content']
        );
        $items = $schema['pages'][0]['items'];
        if ($items === []) {
            throw new InvalidSchemaException([(string)__('A group needs at least one field.')]);
        }
        return [
            'fields' => $items,
            'translations' => $this->translator->normalize($translations, ['pages' => [['items' => $items]]], [], $storeIds),
        ];
    }
}
