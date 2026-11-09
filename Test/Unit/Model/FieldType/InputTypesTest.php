<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Api\FieldTypeInterface;
use Furan\FormBuilder\Model\FieldType\Color;
use Furan\FormBuilder\Model\FieldType\Date;
use Furan\FormBuilder\Model\FieldType\DateTime;
use Furan\FormBuilder\Model\FieldType\Email;
use Furan\FormBuilder\Model\FieldType\Hidden;
use Furan\FormBuilder\Model\FieldType\Number;
use Furan\FormBuilder\Model\FieldType\Tel;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\FieldType\Textarea;
use Furan\FormBuilder\Model\FieldType\Time;
use Furan\FormBuilder\Model\FieldType\Url;
use PHPUnit\Framework\TestCase;

class InputTypesTest extends TestCase
{
    /** @return FieldTypeInterface[] */
    private function all(): array
    {
        return [new Text(), new Textarea(), new Email(), new Tel(), new Url(), new Number(), new Date(),
            new Time(), new DateTime(), new Color(), new Hidden()];
    }

    public function testItNormalisesScalarValuesByTrimmingAndStrippingControlCharacters(): void
    {
        $text = new Text();
        $this->assertSame('hello', $text->normalizeValue("  hel\x00lo\x07 \r ", []));
        $this->assertSame('42', $text->normalizeValue(42, []));
    }

    public function testItReturnsNullForArrayValuesOnSingleValueTypes(): void
    {
        foreach ($this->all() as $type) {
            $this->assertNull($type->normalizeValue(['a'], []), $type->getCode());
        }
    }

    public function testItKeepsNewlinesOnlyForTextarea(): void
    {
        $this->assertSame("a\nb\tc", (new Textarea())->normalizeValue("a\r\nb\tc", []));
        $this->assertSame('a b', (new Text())->normalizeValue("a\nb", []));
        $this->assertSame('#abcdef', (new Color())->normalizeValue(' #ABCDEF ', []));
    }

    public function testItTruncatesValuesToTheTypeHardCap(): void
    {
        $this->assertSame(20000, mb_strlen((string)(new Textarea())->normalizeValue(str_repeat('é', 20005), [])));
        $this->assertSame(255, mb_strlen((string)(new Hidden())->normalizeValue(str_repeat('a', 300), [])));
        $this->assertSame(1000, mb_strlen((string)(new Text())->normalizeValue(str_repeat('a', 1005), [])));
    }

    public function testItValidatesIntrinsicFormatsForEmailTelUrlNumberDateTimeDatetimeAndColor(): void
    {
        $cases = [
            [new Email(), 'a@b.co', 'a@b'],
            [new Tel(), '+44 1234 567', 'abc'],
            [new Url(), 'https://example.com', 'example.com'],
            [new Number(), '-12.5', '1e5'],
            [new Date(), '2024-02-29', '29/02/2024'],
            [new Time(), '23:59', '24:00'],
            [new DateTime(), '2024-02-29T10:30', '2024-02-29 10:30'],
            [new Color(), '#a1b2c3', '#abc'],
        ];
        foreach ($cases as [$type, $good, $bad]) {
            $this->assertNull($type->validateValue($good, [], []), $type->getCode());
            $this->assertNotNull($type->validateValue($bad, [], []), $type->getCode());
        }
        $this->assertNull((new Text())->validateValue('anything', [], []));
        $this->assertNull((new Textarea())->validateValue('anything', [], []));
        $this->assertNull((new Hidden())->validateValue('anything', [], []));
    }

    public function testItRejectsImpossibleCalendarDates(): void
    {
        $this->assertNotNull((new Date())->validateValue('2023-02-29', [], []));
        $this->assertNotNull((new Date())->validateValue('2024-13-01', [], []));
        $this->assertNotNull((new DateTime())->validateValue('2023-02-30T10:00', [], []));
    }

    public function testItExposesAllowedRulesAndSettingsSpecPerType(): void
    {
        $this->assertSame(['min_length', 'max_length', 'pattern', 'preset', 'alpha', 'alphanumeric', 'matches'], (new Text())->getAllowedRules());
        $this->assertSame(['min_length', 'max_length', 'pattern', 'preset'], (new Textarea())->getAllowedRules());
        $this->assertSame(['max_length', 'matches', 'pattern', 'preset'], (new Email())->getAllowedRules());
        $this->assertSame(['min_length', 'max_length', 'pattern', 'preset'], (new Tel())->getAllowedRules());
        $this->assertSame(['max_length'], (new Url())->getAllowedRules());
        $this->assertSame(['min', 'max', 'integer'], (new Number())->getAllowedRules());
        $this->assertSame(['min_date', 'max_date'], (new Date())->getAllowedRules());
        $this->assertSame(['min_date', 'max_date'], (new DateTime())->getAllowedRules());
        $this->assertSame([], (new Time())->getAllowedRules());
        $this->assertSame([], (new Color())->getAllowedRules());
        $this->assertSame([], (new Hidden())->getAllowedRules());

        $this->assertSame(['rows' => ['type' => 'int', 'default' => 5, 'min' => 2, 'max' => 20]], (new Textarea())->getSettingsSpec());
        $affixes = [
            'prefix' => ['type' => 'string', 'translatable' => true, 'default' => '', 'max_length' => 20],
            'suffix' => ['type' => 'string', 'translatable' => true, 'default' => '', 'max_length' => 20],
        ];
        $this->assertSame(['step' => ['type' => 'string', 'default' => 'any']] + $affixes, (new Number())->getSettingsSpec());
        $mask = ['mask' => ['type' => 'string', 'format' => 'mask', 'default' => '', 'max_length' => 40]];
        $this->assertSame($affixes + $mask, (new Text())->getSettingsSpec());
        $this->assertSame($mask, (new Tel())->getSettingsSpec());
        $this->assertSame([
            'source' => [
                'type' => 'enum', 'default' => 'static', 'values' => ['static', 'query', 'page_url', 'referrer'],
                'value_labels' => ['static' => 'Fixed value', 'query' => 'Query parameter', 'page_url' => 'Page URL', 'referrer' => 'Referrer'],
            ],
            'query_param' => ['type' => 'string', 'default' => ''],
        ], (new Hidden())->getSettingsSpec());

        foreach ($this->all() as $type) {
            $this->assertSame('input', $type->getCategory());
            $this->assertFalse($type->hasOptions());
            $this->assertFalse($type->isFile());
            $this->assertFalse($type->isMultiValue());
        }
        $this->assertSame('Furan_FormBuilder::item/datetime.phtml', (new DateTime())->getTemplate());
        $this->assertSame('datetime', (new DateTime())->getCode());
    }

    public function testItReturnsTheSameMessageFromValidateValueAndGetInvalidMessage(): void
    {
        foreach ($this->all() as $type) {
            $message = $type->getInvalidMessage([]);
            if (in_array($type->getCode(), ['text', 'textarea', 'hidden'], true)) {
                $this->assertNull($message);
                continue;
            }
            $this->assertNotSame('', $message);
            $this->assertSame($message, $type->validateValue("\x01bad", [], []), $type->getCode());
        }
        $this->assertSame('Please enter a valid date.', (new Date())->getInvalidMessage([]));
        $this->assertSame('Please enter a valid time.', (new Time())->getInvalidMessage([]));
        $this->assertSame('Please choose a valid colour.', (new Color())->getInvalidMessage([]));
    }
}
