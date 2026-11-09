<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Model\FieldType\Cards;
use Furan\FormBuilder\Model\FieldType\Consent;
use Furan\FormBuilder\Model\FieldType\Matrix;
use Furan\FormBuilder\Model\FieldType\Month;
use Furan\FormBuilder\Model\FieldType\Scale;
use Furan\FormBuilder\Model\FieldType\Suggest;
use Furan\FormBuilder\Model\FieldType\Toggle;
use PHPUnit\Framework\TestCase;

class NewTypesTest extends TestCase
{
    private function matrixField(array $over = []): array
    {
        return $over + [
            'required' => true,
            'settings' => ['rows' => ['Speed', 'Price'], 'require_all' => true],
            'options' => [['label' => 'Good', 'value' => 'g'], ['label' => 'Bad', 'value' => 'b']],
        ];
    }

    public function testItAcceptsOnlyYesForConsent(): void
    {
        $type = new Consent();
        $this->assertSame('choice', $type->getCategory());
        $this->assertNull($type->validateValue('yes', [], []));
        $this->assertNotNull($type->validateValue('on', ['settings' => ['checked_value' => 'on']], []));
    }

    public function testItAcceptsOnlyTheConfiguredOnValueForToggle(): void
    {
        $type = new Toggle();
        $this->assertNull($type->validateValue('yes', [], []));
        $this->assertNull($type->validateValue('1', ['settings' => ['on_value' => '1']], []));
        $this->assertNotNull($type->validateValue('yes', ['settings' => ['on_value' => '1']], []));
        // "0" is a real value, not a fallback trigger (matches the template and JS).
        $this->assertNull($type->validateValue('0', ['settings' => ['on_value' => '0']], []));
        $this->assertNull((new \Furan\FormBuilder\Model\FieldType\Checkbox())->validateValue('0', ['settings' => ['checked_value' => '0']], []));
    }

    public function testItValidatesScaleValuesAgainstTheConfiguredRange(): void
    {
        $type = new Scale();
        $nps = ['settings' => ['min' => 0, 'max' => 10]];
        $this->assertNull($type->validateValue('0', $nps, []));
        $this->assertNull($type->validateValue('10', $nps, []));
        $this->assertNotNull($type->validateValue('11', $nps, []));
        $this->assertNotNull($type->validateValue('0', [], []));
        $this->assertNotNull($type->validateValue('x', $nps, []));
        $this->assertNotNull($type->validateValue(['3'], $nps, []));
        $this->assertSame('Please choose a value between 0 and 10.', $type->getInvalidMessage($nps));
    }

    public function testItChecksCardsAgainstTheirOptions(): void
    {
        $type = new Cards();
        $field = ['options' => [['label' => 'A', 'value' => 'a']]];
        $this->assertTrue($type->hasOptions());
        $this->assertNull($type->validateValue('a', $field, []));
        $this->assertNotNull($type->validateValue('z', $field, []));
    }

    public function testItValidatesTheMonthFormat(): void
    {
        $type = new Month();
        $this->assertNull($type->validateValue('2025-12', [], []));
        $this->assertNotNull($type->validateValue('2025-13', [], []));
        $this->assertNotNull($type->validateValue('2025-1', [], []));
        $this->assertNotNull($type->validateValue("2025-12\n", [], []));
    }

    public function testItAcceptsFreeTextForSuggestWithoutOptionMembership(): void
    {
        $type = new Suggest();
        $this->assertTrue($type->hasOptions());
        $this->assertSame('input', $type->getCategory());
        $this->assertSame('Anything', $type->normalizeValue(' Anything ', []));
        $this->assertNull($type->validateValue('Anything', ['options' => [['label' => 'A', 'value' => 'a']]], []));
        $this->assertContains('pattern', $type->getAllowedRules());
    }

    public function testItBuildsMatrixAnswersFromValidRowsAndColumnsOnly(): void
    {
        $type = new Matrix();
        $this->assertTrue($type->isMultiValue());
        $this->assertSame(
            ['Speed: Good', 'Price: Bad'],
            $type->normalizeValue([1 => 'b', 0 => 'g'], $this->matrixField())
        );
        $this->assertSame(['Price: Bad'], $type->normalizeValue(['0' => '', '1' => ' b '], $this->matrixField()));
        $this->assertNull($type->normalizeValue('g', $this->matrixField()));
        $this->assertSame([], $type->normalizeValue([], $this->matrixField()));
    }

    public function testItFlagsInvalidMatrixRowsColumnsAndShapes(): void
    {
        $type = new Matrix();
        $field = $this->matrixField();
        foreach ([[5 => 'g'], ['x' => 'g'], [0 => 'zzz'], [0 => ['g']], [-1 => 'g'], ['01' => 'g']] as $posted) {
            $value = $type->normalizeValue($posted, $field);
            $this->assertNotNull($type->validateValue($value, $field, []), json_encode($posted));
        }
        $mixed = $type->normalizeValue([0 => 'g', 7 => 'g'], $field);
        $this->assertSame('Please choose a valid option.', $type->validateValue($mixed, $field, []));
    }

    public function testItRequiresEveryMatrixRowWhenRequiredAndRequireAll(): void
    {
        $type = new Matrix();
        $field = $this->matrixField();
        $this->assertSame('Please answer every row.', $type->validateValue(['Speed: Good'], $field, []));
        $this->assertNull($type->validateValue(['Speed: Good', 'Price: Bad'], $field, []));

        $field['settings']['require_all'] = false;
        $this->assertNull($type->validateValue(['Speed: Good'], $field, []));
        $field['settings']['require_all'] = true;
        $field['required'] = false;
        $this->assertNull($type->validateValue(['Speed: Good'], $field, []));
    }
}
