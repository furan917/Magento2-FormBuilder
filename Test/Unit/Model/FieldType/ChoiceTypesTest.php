<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Model\FieldType\Checkbox;
use Furan\FormBuilder\Model\FieldType\Checkboxes;
use Furan\FormBuilder\Model\FieldType\Country;
use Furan\FormBuilder\Model\FieldType\File;
use Furan\FormBuilder\Model\FieldType\Multiselect;
use Furan\FormBuilder\Model\FieldType\Radio;
use Furan\FormBuilder\Model\FieldType\Range;
use Furan\FormBuilder\Model\FieldType\Rating;
use Furan\FormBuilder\Model\FieldType\Select;
use Magento\Directory\Model\AllowedCountries;
use PHPUnit\Framework\TestCase;

class ChoiceTypesTest extends TestCase
{
    private const INVALID = 'Please choose a valid option.';

    private function field(array $settings = []): array
    {
        return [
            'options' => [['label' => 'Red', 'value' => 'red'], ['label' => 'Blue', 'value' => 'blue']],
            'settings' => $settings,
        ];
    }

    private function country(array $allowed): Country
    {
        $allowedCountries = $this->createStub(AllowedCountries::class);
        $allowedCountries->method('getAllowedCountries')->willReturn($allowed);
        return new Country($allowedCountries, $this->createStub(\Magento\Framework\Locale\ListsInterface::class));
    }

    public function testItAcceptsOnlyConfiguredOptionValuesForSelectAndRadio(): void
    {
        foreach ([new Select(), new Radio()] as $type) {
            $this->assertNull($type->validateValue('red', $this->field(), []), $type->getCode());
            $this->assertSame(self::INVALID, $type->validateValue('green', $this->field(), []));
            $this->assertSame('red', $type->normalizeValue(' red ', []));
            $this->assertNull($type->normalizeValue(['red'], []));
        }
    }

    public function testItNormalisesMultiValueInputToAUniqueList(): void
    {
        foreach ([new Multiselect(), new Checkboxes()] as $type) {
            $this->assertSame(['a', 'b'], $type->normalizeValue([' a ', 'b', 'a', '', 'b'], []));
            $this->assertSame(['a'], $type->normalizeValue('a', []));
            $this->assertSame([], $type->normalizeValue('', []));
            $this->assertNull($type->normalizeValue([['x']], []));
            $this->assertNull($type->normalizeValue(new \stdClass(), []));
            $many = array_map('strval', range(1, 600));
            $this->assertCount(500, $type->normalizeValue($many, []));
        }
    }

    public function testItRejectsMultiValuesOutsideTheOptions(): void
    {
        foreach ([new Multiselect(), new Checkboxes()] as $type) {
            $this->assertNull($type->validateValue(['red', 'blue'], $this->field(), []));
            $this->assertSame(self::INVALID, $type->validateValue(['red', 'green'], $this->field(), []));
        }
    }

    public function testItRequiresTheCheckedValueForASingleCheckbox(): void
    {
        $type = new Checkbox();
        $this->assertNull($type->validateValue('yes', ['settings' => []], []));
        $this->assertSame(self::INVALID, $type->validateValue('no', ['settings' => []], []));
        $this->assertNull($type->validateValue('agree', ['settings' => ['checked_value' => 'agree']], []));
        $this->assertSame(self::INVALID, $type->validateValue('yes', ['settings' => ['checked_value' => 'agree']], []));
    }

    public function testItValidatesRatingValuesWithinTheConfiguredMaximum(): void
    {
        $type = new Rating();
        $this->assertNull($type->validateValue('5', ['settings' => []], []));
        $this->assertNotNull($type->validateValue('6', ['settings' => []], []));
        $this->assertNull($type->validateValue('8', ['settings' => ['max' => 10]], []));
        $this->assertNotNull($type->validateValue('0', ['settings' => []], []));
        $this->assertNotNull($type->validateValue('2.5', ['settings' => []], []));
        $this->assertNotNull($type->validateValue('abc', ['settings' => []], []));
        $this->assertSame(
            'Please choose a value between 1 and 5.',
            $type->validateValue('9', ['settings' => []], [])
        );
    }

    public function testItValidatesRangeValuesWithinBoundsAndOnTheStep(): void
    {
        $type = new Range();
        $field = ['settings' => ['min' => 10, 'max' => 20, 'step' => 2.5]];
        $this->assertNull($type->validateValue('12.5', $field, []));
        $this->assertNull($type->validateValue('20', $field, []));
        $this->assertNotNull($type->validateValue('13', $field, []));
        $this->assertNotNull($type->validateValue('9', $field, []));
        $this->assertNotNull($type->validateValue('21', $field, []));
        $this->assertNotNull($type->validateValue('x', $field, []));
        $this->assertSame(
            'Please choose a value between 10 and 20.',
            $type->validateValue('30', $field, [])
        );
        $this->assertNull($type->validateValue('7', ['settings' => ['step' => 0]], []));
        $this->assertNull($type->validateValue('7', ['settings' => []], []));
        $this->assertNull($type->validateValue('0.3', ['settings' => ['min' => 0, 'max' => 1, 'step' => 0.1]], []));
    }

    public function testItValidatesCountriesAgainstTheAllowedCountriesForTheStore(): void
    {
        $type = $this->country(['GB' => 'GB', 'FR' => 'FR']);
        $this->assertNull($type->validateValue('GB', [], ['store_id' => 1]));
        $this->assertSame(self::INVALID, $type->validateValue('US', [], ['store_id' => 1]));
        $this->assertSame(self::INVALID, $type->validateValue(['GB'], [], ['store_id' => 1]));
    }

    public function testItReportsCategoriesAndFlagsForEachType(): void
    {
        $types = [new Select(), new Multiselect(), new Radio(), new Checkboxes(), new Checkbox(),
            $this->country([]), new Rating(), new Range(), new File()];
        $expect = [
            'select' => ['choice', true, false, false], 'multiselect' => ['choice', true, true, false],
            'radio' => ['choice', true, false, false], 'checkboxes' => ['choice', true, true, false],
            'checkbox' => ['choice', false, false, false], 'country' => ['choice', false, false, false],
            'rating' => ['choice', false, false, false], 'range' => ['choice', false, false, false],
            'file' => ['advanced', false, false, true],
        ];
        foreach ($types as $type) {
            $this->assertSame(
                $expect[$type->getCode()],
                [$type->getCategory(), $type->hasOptions(), $type->isMultiValue(), $type->isFile()],
                $type->getCode()
            );
            $this->assertNotSame('', $type->getLabel());
        }
        $file = new File();
        $this->assertNull($file->validateValue('x', [], []));
        $this->assertNull($file->getInvalidMessage([]));
        $this->assertSame(0, $file->getSettingsSpec()['max_size_mb']['min']);
        $this->assertSame([], $file->getSettingsSpec()['extensions']['default']);
        $this->assertSame(['min_selected', 'max_selected'], (new Checkboxes())->getAllowedRules());
        $this->assertSame(0.0001, (new Range())->getSettingsSpec()['step']['min']);
        $this->assertEquals(['min' => 3, 'max' => 10, 'default' => 5, 'type' => 'int'], (new Rating())->getSettingsSpec()['max']);
    }
}
