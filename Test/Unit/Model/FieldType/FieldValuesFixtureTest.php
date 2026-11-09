<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Model\FieldType\Cards;
use Furan\FormBuilder\Model\FieldType\Consent;
use Furan\FormBuilder\Model\FieldType\ItemList;
use Furan\FormBuilder\Model\FieldType\Matrix;
use Furan\FormBuilder\Model\FieldType\Month;
use Furan\FormBuilder\Model\FieldType\Number;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Scale;
use Furan\FormBuilder\Model\FieldType\Suggest;
use Furan\FormBuilder\Model\FieldType\Toggle;
use Furan\FormBuilder\Model\Validation\Rule\MaxLength;
use PHPUnit\Framework\TestCase;

/** Runs the cases shared with Test/js/new-types.test.js. */
class FieldValuesFixtureTest extends TestCase
{
    public function testItPassesEveryCaseInTheSharedFieldValuesFixture(): void
    {
        $pool = new Pool([
            'consent' => new Consent(), 'toggle' => new Toggle(), 'scale' => new Scale(), 'month' => new Month(),
            'cards' => new Cards(), 'suggest' => new Suggest(), 'matrix' => new Matrix(),
            'list' => new ItemList(), 'number' => new Number(),
        ]);
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/field-values.json'), true);
        $this->assertNotEmpty($cases);
        foreach ($cases as $case) {
            $field = $case['field'];
            $type = $pool->get($field['type']);
            $value = $type->normalizeValue($case['raw'], $field) ?? ($type->isMultiValue() ? [] : '');
            $this->assertSame($case['normalized'], $value, $case['name']);
            $this->assertSame($case['valid'], $this->isValid($type, $value, $field), $case['name']);
            if (isset($case['message'])) {
                $this->assertSame($case['message'], $type->validateValue($value, $field, []), $case['name']);
            }
        }
    }

    private function isValid(object $type, string|array $value, array $field): bool
    {
        if ($value === '' || $value === []) {
            return !$field['required'];
        }
        if ($type->validateValue($value, $field, []) !== null) {
            return false;
        }
        foreach ($field['rules'] as $rule) {
            if ($rule['rule'] === 'max_length' && !(new MaxLength())->validate($value, $rule['value'], [])) {
                return false;
            }
        }
        return true;
    }
}
