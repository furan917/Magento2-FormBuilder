<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Validation;

use Furan\FormBuilder\Model\Validation\BuiltInRules;
use Furan\FormBuilder\Model\Validation\Mask;
use Furan\FormBuilder\Model\Validation\PatternMatcher;
use Furan\FormBuilder\Model\Validation\RepeatAnalyzer;
use PHPUnit\Framework\TestCase;

class MaskTest extends TestCase
{
    private Mask $mask;

    protected function setUp(): void
    {
        $this->mask = new Mask();
    }

    public function testItFormatsMasksTheSameAfterTheDecision(): void
    {
        $this->assertSame('12-34-56', Mask::format('123456', 'uk_sort_code'));
        $this->assertSame('(12) 34', Mask::format('1234', '(99) 99'));
        $this->assertSame('1234', Mask::raw('(12) 34', '(99) 99'));
    }

    public function testItFormatsASortCode(): void
    {
        $this->assertSame('12-34-56', $this->mask->format('123456', 'uk_sort_code'));
        $this->assertSame('12-34-56', $this->mask->format('12 34 56', 'uk_sort_code'));
    }

    public function testItUpperCasesAndGroupsAnIban(): void
    {
        $this->assertSame(
            'GB82 WEST 1234 5698 7654 32',
            $this->mask->format('gb82west12345698765432', 'iban_format')
        );
    }

    public function testItFormatsAUkPostcodeWithItsSpace(): void
    {
        $this->assertSame('SW1A 1AA', $this->mask->format('sw1a1aa', 'uk_postcode'));
        $this->assertSame('M1 1AE', $this->mask->format(' m11ae ', 'uk_postcode'));
    }

    public function testItAppliesACustomMaskOfDigitsAndLetters(): void
    {
        $this->assertSame('(555) 123-4567', $this->mask->format('5551234567', '(999) 999-9999'));
        $this->assertSame('abc-123', $this->mask->format('abc123', 'aaa-999'));
        $this->assertSame('ab-12', $this->mask->format('ab12', '**-**'));
    }

    public function testItUpperCasesLettersForACapitalLetterMaskToken(): void
    {
        $this->assertSame('AB-1234', $this->mask->format('ab1234', 'AA-9999'));
        $this->assertSame('AB1234', $this->mask->raw('ab1234', 'AA-9999'));
        $this->assertSame('éß', $this->mask->format('éß', 'AA'));
    }

    public function testItKeepsTheTypedCaseForALowerLetterMaskToken(): void
    {
        $this->assertSame('Ab-12', $this->mask->format('Ab12', 'aa-99'));
        $this->assertSame('Ab-12', $this->mask->format('ab12', 'Aa-99'));
    }

    public function testItKeepsExtraCharactersBeyondTheMask(): void
    {
        $this->assertSame('12-3456', $this->mask->format('123456', '99-99'));
        $this->assertSame('ab12', $this->mask->format('ab12', 'aaa-999'));
        $this->assertSame('12-34-56789', $this->mask->format('123456789', 'uk_sort_code'));
    }

    public function testItReturnsTheRawValueWithoutMaskCharacters(): void
    {
        $this->assertSame('5551234567', $this->mask->raw('(555) 123-4567', '(999) 999-9999'));
        $this->assertSame('123456', $this->mask->raw('12-34-56', 'uk_sort_code'));
        $this->assertSame('GB82WEST12345698765432', $this->mask->raw('GB82 WEST 1234 5698 7654 32', 'iban_format'));
    }

    public function testItPassesTheSharedMaskFixturesInPhpAndJs(): void
    {
        $fixture = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/masks.json'), true);
        $this->assertNotEmpty($fixture['cases']);
        foreach ($fixture['cases'] as $case) {
            $label = $case['mask'] . ' <- ' . $case['value'];
            $formatted = $this->mask->format($case['value'], $case['mask']);
            $this->assertSame($case['formatted'], $formatted, 'format ' . $label);
            $this->assertSame($case['raw'], $this->mask->raw($case['value'], $case['mask']), 'raw ' . $label);
            $this->assertSame($formatted, $this->mask->format($formatted, $case['mask']), 'idempotent ' . $label);
        }
    }

    public function testItProducesValuesThatPassEachMatchingPresetRule(): void
    {
        $matcher = new PatternMatcher(new RepeatAnalyzer());
        $rules = (new BuiltInRules())->all();
        $checked = 0;
        foreach ($this->mask->presetCodes() as $code) {
            $this->assertArrayHasKey($code, $rules, $code);
            $example = $rules[$code]['example'];
            $unformatted = $this->mask->raw($example, $code);
            foreach ([$example, $unformatted, strtolower($unformatted)] as $input) {
                $out = $this->mask->format($input, $code);
                $this->assertTrue($matcher->matches($rules[$code]['pattern'], $out), $code . ': ' . $input . ' -> ' . $out);
            }
            $checked++;
        }
        $this->assertGreaterThanOrEqual(11, $checked);
    }

    public function testItReturnsInvalidUtf8Unchanged(): void
    {
        foreach (['99-99', 'uk_postcode', 'uk_phone'] as $mask) {
            $this->assertSame("12\xC3(", $this->mask->format("12\xC3(", $mask));
            $this->assertSame("12\xC3(", $this->mask->raw("12\xC3(", $mask));
        }
        $this->assertSame('12', $this->mask->format('12', "9\xFF"));
    }

    public function testItHandlesHostileInputInLinearTime(): void
    {
        $big = str_repeat('9 -a', 50000);
        $start = microtime(true);
        foreach (['99-99', 'iban_format', 'uk_phone', 'uk_postcode', '**-**'] as $mask) {
            $this->assertIsString($this->mask->format($big, $mask));
            $this->assertIsString($this->mask->raw($big, $mask));
        }
        $this->assertLessThan(5.0, microtime(true) - $start);
    }
}
