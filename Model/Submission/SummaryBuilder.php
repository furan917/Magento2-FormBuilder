<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Model\FieldType\Pool;

class SummaryBuilder
{
    private const VALUES = 3;
    private const LENGTH = 255;

    public function __construct(private readonly Pool $fieldTypes, private readonly ValueFormatter $formatter)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $schemaFields field items (name, type, options)
     * @param array<string, mixed> $values answers by field name
     */
    public function build(array $schemaFields, array $values): string
    {
        $parts = [];
        foreach ($schemaFields as $field) {
            $name = (string)($field['name'] ?? '');
            $type = (string)($field['type'] ?? '');
            if ($this->fieldTypes->isServerValue($type) || $this->fieldTypes->isFile($type) || !array_key_exists($name, $values)) {
                continue;
            }
            $value = ValueFormatter::withLabels($this->formatter->optionLabels($field), $values[$name]);
            // Literal text: every reader escapes it, as the detail view does.
            $text = trim(implode(', ', $this->flatten($value)));
            if ($text === '') {
                continue;
            }
            $parts[] = $text;
            if (count($parts) === self::VALUES) {
                break;
            }
        }
        return mb_substr(implode(' · ', $parts), 0, self::LENGTH);
    }

    /**
     * @return string[]
     */
    private function flatten(mixed $value): array
    {
        if (!is_array($value)) {
            return is_scalar($value) ? [(string)$value] : [];
        }
        $out = [];
        foreach ($value as $item) {
            array_push($out, ...$this->flatten($item));
        }
        return $out;
    }
}
