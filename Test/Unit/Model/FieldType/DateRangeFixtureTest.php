<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Model\FieldType\DateRange;
use PHPUnit\Framework\TestCase;

/** Runs the cases shared with the JS date range tests. */
class DateRangeFixtureTest extends TestCase
{
    public function testItPassesEveryCaseInTheSharedDateRangeFixture(): void
    {
        $type = new DateRange();
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/date-range-values.json'), true);
        $this->assertNotEmpty($cases);
        foreach ($cases as $case) {
            $field = $case['field'];
            $value = $type->normalizeValue($case['raw'], $field) ?? '';
            $this->assertSame($case['normalized'], $value, $case['name']);
            $valid = $value === '' ? !$field['required'] : $type->validateValue($value, $field, []) === null;
            $this->assertSame($case['valid'], $valid, $case['name']);
        }
    }
}
