<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use PHPUnit\Framework\TestCase;

class SettingHintsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function spec(string $code, string $setting): array
    {
        return FieldPools::builtIn()->get($code)->getSettingsSpec()[$setting];
    }

    public function testItExplainsSettingsWhoseValuesAreNotSelfEvident(): void
    {
        $hints = [
            ['number', 'step', "'any' or a positive number. Steps count from the Minimum rule."],
            ['rating', 'max', 'From 3 to 10.'],
            ['list', 'max_items', 'From 2 to 20.'],
            ['date_range', 'min_days', 'Counts the start and end days. 0 = no limit.'],
            ['date_range', 'max_days', 'Counts the start and end days. 0 = no limit.'],
            ['newsletter', 'email_field', "Empty = the form's customer email field."],
            ['consent', 'privacy_url', 'A path such as /privacy, or a full URL on this store.'],
            ['matrix', 'rows', 'One row per line; options are the columns.'],
            ['checkbox', 'checked_value', 'Saved when on; conditions compare against it.'],
            ['toggle', 'on_value', 'Saved when on; conditions compare against it.'],
        ];
        foreach ($hints as [$code, $setting, $hint]) {
            $this->assertSame($hint, $this->spec($code, $setting)['hint'] ?? null, $code . '.' . $setting);
        }
    }

    public function testItUsesTypedBuilderInputsForDatesEmailFieldsAndTheScaleMinimum(): void
    {
        $this->assertSame('date', $this->spec('date_range', 'min_date')['format'] ?? null);
        $this->assertSame('date', $this->spec('date_range', 'max_date')['format'] ?? null);
        $this->assertSame('email_field', $this->spec('newsletter', 'email_field')['format'] ?? null);
        $min = $this->spec('scale', 'min');
        $this->assertSame('int', $min['type']);
        $this->assertSame(['0', '1'], $min['values'] ?? null);
    }

    public function testItCaptionsTheChoiceLayouts(): void
    {
        foreach (['radio', 'checkboxes'] as $code) {
            $this->assertSame(['stacked' => 'Stacked', 'inline' => 'Inline'], $this->spec($code, 'layout')['value_labels'] ?? null, $code);
        }
    }

    public function testTheConsentLinkTextDefaultsToTheTranslatedFallback(): void
    {
        $this->assertSame('', $this->spec('consent', 'privacy_label')['default']);
    }
}
