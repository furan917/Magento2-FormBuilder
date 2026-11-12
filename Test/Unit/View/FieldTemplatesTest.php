<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\View;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Furan\FormBuilder\Test\Unit\View\Stub\FakeFormParent;
use Furan\FormBuilder\Test\Unit\View\Stub\FakeItemBlock;
use PHPUnit\Framework\TestCase;

class FieldTemplatesTest extends TestCase
{
    private const SIMPLE_TYPES = [
        'text', 'textarea', 'email', 'tel', 'url', 'number', 'date', 'time', 'datetime', 'color',
        'select', 'multiselect', 'country', 'file', 'range',
    ];
    private const MAX_LENGTHS = ['text' => 1000, 'email' => 1000, 'tel' => 1000, 'url' => 1000, 'suggest' => 1000, 'textarea' => 20000, 'list' => 255, 'address' => 255];

    public function testItRendersFieldsWithIdsLabelsHelpAndErrorHooksPerTheMarkupContract(): void
    {
        $xpath = $this->xpath($this->render('text', [
            'label' => 'First name', 'help' => 'As on passport', 'required' => true,
            'width' => 'half', 'css_class' => 'extra-class',
        ]));

        $wrapper = $xpath->query('/html/body/div')->item(0);
        $this->assertInstanceOf(DOMElement::class, $wrapper);
        $this->assertSame('ffb-item ffb-field ffb-type-text ffb-w-half extra-class', $wrapper->getAttribute('class'));
        $this->assertSame('f_test01', $wrapper->getAttribute('data-ffb-item'));
        $this->assertSame('first_name', $wrapper->getAttribute('data-ffb-name'));
        $this->assertSame('text', $wrapper->getAttribute('data-ffb-type'));

        $label = $xpath->query('/html/body/div/label[@class="ffb-label"]')->item(0);
        $this->assertSame('ffb-7-first_name', $label->getAttribute('for'));
        $this->assertSame('First name*', $label->textContent);
        $this->assertSame('true', $xpath->query('.//span[@class="ffb-req"]', $label)->item(0)->getAttribute('aria-hidden'));

        $input = $xpath->query('/html/body/div/input')->item(0);
        $this->assertSame('ffb-7-first_name', $input->getAttribute('id'));
        $this->assertSame('ffb-input', $input->getAttribute('class'));
        $this->assertSame('fields[first_name]', $input->getAttribute('name'));
        $this->assertSame('text', $input->getAttribute('type'));
        $this->assertTrue($input->hasAttribute('required'));
        $this->assertSame('ffb-7-first_name-help ffb-7-first_name-error', $input->getAttribute('aria-describedby'));

        $help = $xpath->query('/html/body/div/p[@class="ffb-help"]')->item(0);
        $this->assertSame('ffb-7-first_name-help', $help->getAttribute('id'));
        $this->assertSame('As on passport', $help->textContent);

        $error = $wrapper->lastChild;
        while ($error !== null && !$error instanceof DOMElement) {
            $error = $error->previousSibling;
        }
        $this->assertSame('ffb-error', $error->getAttribute('class'));
        $this->assertSame('ffb-7-first_name-error', $error->getAttribute('id'));
        $this->assertTrue($error->hasAttribute('data-ffb-error'));
        $this->assertTrue($error->hasAttribute('hidden'));
    }

    public function testItOmitsHelpAndRequiredMarkersWhenNotNeeded(): void
    {
        $xpath = $this->xpath($this->render('text', ['help' => '', 'required' => false]));

        $this->assertSame(0, $xpath->query('/html/body//p[@class="ffb-help"]')->length);
        $this->assertSame(0, $xpath->query('/html/body//*[@class="ffb-req"]')->length);
        $this->assertSame('ffb-7-first_name-error', $xpath->query('/html/body//input')->item(0)->getAttribute('aria-describedby'));
        $this->assertFalse($xpath->query('/html/body//input')->item(0)->hasAttribute('required'));
    }

    public function testItRendersTheHooksForEverySimpleControl(): void
    {
        foreach (self::SIMPLE_TYPES as $type) {
            $xpath = $this->xpath($this->render($type, ['help' => 'Hint']));
            $control = $xpath->query('/html/body//*[@id="ffb-7-first_name"]')->item(0);
            $this->assertNotNull($control, $type);
            $this->assertContains('ffb-input', explode(' ', $control->getAttribute('class')), $type);
            $this->assertSame('ffb-7-first_name-help ffb-7-first_name-error', $control->getAttribute('aria-describedby'), $type);
            $this->assertSame('ffb-7-first_name', $xpath->query('/html/body/div/label')->item(0)->getAttribute('for'), $type);
            $this->assertSame(1, $xpath->query('/html/body/div/p[@id="ffb-7-first_name-error"][@data-ffb-error][@hidden]')->length, $type);
        }
    }

    public function testItEscapesLabelsHelpPlaceholdersDefaultsAndOptionLabels(): void
    {
        $evil = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';
        $base = [
            'label' => $evil, 'help' => $evil, 'placeholder' => $evil, 'default' => $evil,
            'css_class' => $evil, 'name' => 'a"b', 'autocomplete' => $evil,
            'options' => [['label' => $evil, 'value' => $evil]],
            'settings' => ['empty_label' => $evil, 'checked_value' => $evil, 'accept' => $evil, 'extensions' => [$evil]],
        ];
        $base['settings'] += ['details' => $evil, 'privacy_url' => $evil, 'privacy_label' => $evil, 'on_value' => $evil,
            'prefix' => $evil, 'suffix' => $evil, 'low_label' => $evil, 'high_label' => $evil, 'rows' => [$evil]];
        $base['options'][0]['description'] = $evil;
        $newTypes = ['month', 'suggest', 'toggle', 'consent', 'scale', 'cards', 'matrix', 'list'];
        foreach (array_merge(self::SIMPLE_TYPES, $newTypes, ['radio', 'checkboxes', 'checkbox', 'rating', 'hidden']) as $type) {
            $html = $this->render($type, $base);
            $this->assertStringNotContainsString('<script', $html, $type);
            $this->assertStringNotContainsString('<img', $html, $type);
            $this->assertStringNotContainsString($evil, $html, $type);
        }

        $xpath = $this->xpath($this->render('text', $base));
        $this->assertSame($evil, $xpath->query('/html/body//input')->item(0)->getAttribute('placeholder'));
        $this->assertSame($evil, $xpath->query('/html/body//input')->item(0)->getAttribute('value'));
        $this->assertSame($evil, $xpath->query('/html/body//p[@class="ffb-help"]')->item(0)->textContent);

        $xpath = $this->xpath($this->render('select', $base));
        $options = $xpath->query('/html/body//option');
        $this->assertSame($evil, $options->item(0)->textContent);
        $this->assertSame($evil, $options->item(1)->textContent);
        $this->assertSame($evil, $options->item(1)->getAttribute('value'));

        $xpath = $this->xpath($this->render('textarea', $base));
        $this->assertSame($evil, $xpath->query('/html/body//textarea')->item(0)->textContent);
    }

    public function testItRendersChoiceGroupsInFieldsetsWithLegendsAndIndexedOptionIds(): void
    {
        $options = [['label' => 'Email', 'value' => 'email'], ['label' => 'Phone', 'value' => 'phone']];
        foreach (['radio' => 'radio', 'checkboxes' => 'checkbox'] as $type => $inputType) {
            $xpath = $this->xpath($this->render($type, [
                'label' => 'Contact', 'required' => true, 'options' => $options,
                'help' => 'Pick', 'settings' => ['layout' => 'inline'], 'default' => 'phone',
            ]));
            $fieldset = $xpath->query('/html/body/fieldset')->item(0);
            $this->assertSame("ffb-item ffb-field ffb-type-$type ffb-w-full ffb-layout-inline", $fieldset->getAttribute('class'), $type);
            $this->assertSame('f_test01', $fieldset->getAttribute('data-ffb-item'), $type);
            $this->assertSame('first_name', $fieldset->getAttribute('data-ffb-name'), $type);
            $this->assertSame('ffb-7-first_name-help ffb-7-first_name-error', $fieldset->getAttribute('aria-describedby'), $type);
            $this->assertSame('Contact*', $xpath->query('/html/body/fieldset/legend[@class="ffb-label"]')->item(0)->textContent, $type);

            $inputs = $xpath->query('/html/body/fieldset/label[@class="ffb-option"]/input[@class="ffb-choice"]');
            $this->assertSame(2, $inputs->length, $type);
            $this->assertSame($inputType, $inputs->item(0)->getAttribute('type'), $type);
            $this->assertSame('ffb-7-first_name-0', $inputs->item(0)->getAttribute('id'), $type);
            $this->assertSame('ffb-7-first_name-1', $inputs->item(1)->getAttribute('id'), $type);
            $this->assertSame('email', $inputs->item(0)->getAttribute('value'), $type);
            $this->assertFalse($inputs->item(0)->hasAttribute('checked'), $type);
            $this->assertTrue($inputs->item(1)->hasAttribute('checked'), $type);
            $this->assertSame('Phone', $xpath->query('/html/body/fieldset/label/span')->item(1)->textContent, $type);
            $this->assertSame(1, $xpath->query('/html/body/fieldset/p[@id="ffb-7-first_name-error"][@hidden]')->length, $type);
        }

        $xpath = $this->xpath($this->render('radio', ['options' => $options]));
        $this->assertStringContainsString('ffb-layout-stacked', $xpath->query('/html/body/fieldset')->item(0)->getAttribute('class'));
    }

    public function testItUsesArrayNamesForMultiValueFieldsAndFilesNamesForFileFields(): void
    {
        $options = [['label' => 'A', 'value' => 'a']];
        $cases = [
            ['text', '//input', 'fields[first_name]'],
            ['select', '//select', 'fields[first_name]'],
            ['country', '//select', 'fields[first_name]'],
            ['multiselect', '//select', 'fields[first_name][]'],
            ['radio', '//input', 'fields[first_name]'],
            ['rating', '//input', 'fields[first_name]'],
            ['checkboxes', '//input', 'fields[first_name][]'],
            ['checkbox', '//input', 'fields[first_name]'],
            ['file', '//input', 'files[first_name]'],
            ['hidden', '//input', 'fields[first_name]'],
        ];
        foreach ($cases as [$type, $query, $name]) {
            $xpath = $this->xpath($this->render($type, ['options' => $options]));
            $this->assertSame($name, $xpath->query($query)->item(0)->getAttribute('name'), $type);
        }

        $xpath = $this->xpath($this->render('multiselect', ['options' => $options]));
        $this->assertTrue($xpath->query('/html/body//select')->item(0)->hasAttribute('multiple'));
        $this->assertSame(1, $xpath->query('/html/body//option')->length);

        $xpath = $this->xpath($this->render('file', []));
        $this->assertSame('file', $xpath->query('/html/body//input')->item(0)->getAttribute('type'));

        $xpath = $this->xpath($this->render('country', []));
        $this->assertSame(['', 'GB', 'US'], $this->optionValues($xpath));
    }

    public function testItRendersHtmlAttributesFromRulesButNeverPattern(): void
    {
        $rules = [
            ['rule' => 'min_length', 'value' => 2, 'message' => ''],
            ['rule' => 'max_length', 'value' => 40, 'message' => ''],
            ['rule' => 'pattern', 'value' => '[a-z]+', 'message' => ''],
        ];
        $xpath = $this->xpath($this->render('text', [
            'rules' => $rules, 'placeholder' => 'Jane', 'autocomplete' => 'given-name', 'default' => 'Bob',
        ]));
        $input = $xpath->query('/html/body//input')->item(0);
        $this->assertSame('2', $input->getAttribute('minlength'));
        $this->assertSame('40', $input->getAttribute('maxlength'));
        $this->assertSame('Jane', $input->getAttribute('placeholder'));
        $this->assertSame('given-name', $input->getAttribute('autocomplete'));
        $this->assertSame('Bob', $input->getAttribute('value'));
        $this->assertFalse($input->hasAttribute('pattern'));

        $html = $this->render('textarea', ['rules' => $rules, 'settings' => ['rows' => 8], 'default' => 'Hi']);
        $this->assertStringNotContainsString('pattern', $html);
        $textarea = $this->xpath($html)->query('/html/body//textarea')->item(0);
        $this->assertSame('8', $textarea->getAttribute('rows'));
        $this->assertSame('2', $textarea->getAttribute('minlength'));
        $this->assertSame('40', $textarea->getAttribute('maxlength'));
        $this->assertSame('Hi', $textarea->textContent);

        $number = $this->xpath($this->render('number', [
            'rules' => [['rule' => 'min', 'value' => 1, 'message' => ''], ['rule' => 'max', 'value' => 9.5, 'message' => '']],
            'settings' => ['step' => '0.5'],
        ]))->query('/html/body//input')->item(0);
        $this->assertSame('number', $number->getAttribute('type'));
        $this->assertSame('1', $number->getAttribute('min'));
        $this->assertSame('9.5', $number->getAttribute('max'));
        $this->assertSame('0.5', $number->getAttribute('step'));

        $date = $this->xpath($this->render('date', [
            'rules' => [['rule' => 'min_date', 'value' => '2024-01-01'], ['rule' => 'max_date', 'value' => '2024-12-31']],
        ]))->query('/html/body//input')->item(0);
        $this->assertSame('2024-01-01', $date->getAttribute('min'));
        $this->assertSame('2024-12-31', $date->getAttribute('max'));

        $datetime = $this->xpath($this->render('datetime', [
            'rules' => [['rule' => 'min_date', 'value' => '2024-01-01']],
        ]))->query('/html/body//input')->item(0);
        $this->assertSame('datetime-local', $datetime->getAttribute('type'));
        $this->assertSame('2024-01-01T00:00', $datetime->getAttribute('min'));

        $file = $this->xpath($this->render('file', ['settings' => ['accept' => '.pdf,.png']]))->query('/html/body//input')->item(0);
        $this->assertSame('.pdf,.png', $file->getAttribute('accept'));
        $fromExt = $this->xpath($this->render('file', ['settings' => ['extensions' => ['pdf', 'png']]]))->query('/html/body//input')->item(0);
        $this->assertSame('.pdf,.png', $fromExt->getAttribute('accept'));

        foreach (['email' => 'email', 'tel' => 'tel', 'url' => 'url', 'time' => 'time'] as $type => $htmlType) {
            $this->assertSame($htmlType, $this->xpath($this->render($type, []))->query('/html/body//input')->item(0)->getAttribute('type'), $type);
        }
    }

    public function testItRendersRatingRangeCheckboxAndHiddenFieldsPerTheMarkupDetails(): void
    {
        $xpath = $this->xpath($this->render('rating', ['label' => 'Score', 'settings' => ['max' => 4], 'default' => '3', 'render' => ['layout' => 'inline']]));
        $this->assertSame('ffb-item ffb-field ffb-type-rating ffb-w-full ffb-layout-inline', $xpath->query('/html/body/fieldset')->item(0)->getAttribute('class'));
        $this->assertSame(1, $xpath->query('/html/body/fieldset/div[@class="ffb-rating"][@role="radiogroup"]')->length);
        $inputs = $xpath->query('/html/body//div[@class="ffb-rating"]/label[@class="ffb-option"]/input[@type="radio"][@class="ffb-choice"]');
        $this->assertSame(4, $inputs->length);
        $this->assertSame('1', $inputs->item(0)->getAttribute('value'));
        $this->assertSame('4', $inputs->item(3)->getAttribute('value'));
        $this->assertSame('ffb-7-first_name-3', $inputs->item(3)->getAttribute('id'));
        $this->assertSame('fields[first_name]', $inputs->item(0)->getAttribute('name'));
        $this->assertTrue($inputs->item(2)->hasAttribute('checked'));

        $xpath = $this->xpath($this->render('range', ['settings' => ['min' => 10, 'max' => 50, 'step' => 5], 'default' => '30']));
        $input = $xpath->query('/html/body//input')->item(0);
        $this->assertSame('range', $input->getAttribute('type'));
        $this->assertSame(['10', '50', '5', '30'], [
            $input->getAttribute('min'), $input->getAttribute('max'), $input->getAttribute('step'), $input->getAttribute('value'),
        ]);
        $output = $xpath->query('/html/body//output[@class="ffb-range-value"]')->item(0);
        $this->assertTrue($output->hasAttribute('data-ffb-output'));
        $this->assertSame('ffb-7-first_name', $output->getAttribute('for'));
        $this->assertSame('30', $output->textContent);
        $this->assertFalse($input->hasAttribute('data-ffb-unset'));

        $xpath = $this->xpath($this->render('range', []));
        // No default: unanswered until the visitor sets it.
        $this->assertTrue($xpath->query('/html/body//input')->item(0)->hasAttribute('data-ffb-unset'));
        $this->assertTrue($xpath->query('/html/body//input')->item(0)->hasAttribute('data-ffb-nodefault'));
        $this->assertSame('Not set', $xpath->query('/html/body//output')->item(0)->textContent);
        $this->assertSame('0', $xpath->query('/html/body//input')->item(0)->getAttribute('min'));
        $this->assertSame('100', $xpath->query('/html/body//input')->item(0)->getAttribute('max'));
        $this->assertSame('1', $xpath->query('/html/body//input')->item(0)->getAttribute('step'));

        $xpath = $this->xpath($this->render('checkbox', [
            'label' => 'I agree', 'required' => true, 'help' => 'Terms', 'settings' => ['checked_value' => 'yes'],
        ]));
        $this->assertSame('ffb-item ffb-field ffb-type-checkbox ffb-w-full', $xpath->query('/html/body/div')->item(0)->getAttribute('class'));
        $input = $xpath->query('/html/body/div/input')->item(0);
        $this->assertSame('checkbox', $input->getAttribute('type'));
        $this->assertSame('ffb-choice', $input->getAttribute('class'));
        $this->assertSame('ffb-7-first_name', $input->getAttribute('id'));
        $this->assertSame('yes', $input->getAttribute('value'));
        $this->assertTrue($input->hasAttribute('required'));
        $this->assertSame('ffb-7-first_name-help ffb-7-first_name-error', $input->getAttribute('aria-describedby'));
        $this->assertSame('ffb-7-first_name', $xpath->query('/html/body/div/label[@class="ffb-label"]')->item(0)->getAttribute('for'));
        $this->assertSame('I agree*', $xpath->query('/html/body/div/label')->item(0)->textContent);
        $this->assertSame('yes', $this->xpath($this->render('checkbox', []))->query('/html/body//input')->item(0)->getAttribute('value'));

        $xpath = $this->xpath($this->render('hidden', ['label' => 'Src', 'help' => 'h', 'default' => 'abc']));
        $wrapper = $xpath->query('/html/body/div')->item(0);
        $this->assertSame('ffb-item ffb-field ffb-type-hidden', $wrapper->getAttribute('class'));
        $this->assertSame('hidden', $wrapper->getAttribute('data-ffb-type'));
        $this->assertSame('first_name', $wrapper->getAttribute('data-ffb-name'));
        $this->assertSame('f_test01', $wrapper->getAttribute('data-ffb-item'));
        $this->assertSame(1, $xpath->query('/html/body/div/*')->length);
        $input = $xpath->query('/html/body/div/input')->item(0);
        $this->assertSame('hidden', $input->getAttribute('type'));
        $this->assertSame('fields[first_name]', $input->getAttribute('name'));
        $this->assertSame('abc', $input->getAttribute('value'));
    }

    public function testItRendersSelectEmptyLabelAndSelectedDefault(): void
    {
        $options = [['label' => 'A', 'value' => 'a'], ['label' => 'B', 'value' => 'b']];
        $xpath = $this->xpath($this->render('select', [
            'options' => $options, 'default' => 'b', 'settings' => ['empty_label' => 'Pick one'],
        ]));
        $this->assertSame(['', 'a', 'b'], $this->optionValues($xpath));
        $this->assertSame('Pick one', $xpath->query('/html/body//option')->item(0)->textContent);
        $this->assertTrue($xpath->query('/html/body//option')->item(2)->hasAttribute('selected'));
    }

    public function testItRendersPartialsThroughThemeFallbackInsteadOfInclude(): void
    {
        $templates = glob(__DIR__ . '/../../../view/frontend/templates/{,item/}*.phtml', GLOB_BRACE);
        $this->assertNotEmpty($templates);
        foreach ($templates as $template) {
            $this->assertDoesNotMatchRegularExpression('/\b(include|require)(_once)?\b[\s(]/', (string)file_get_contents($template), basename($template));
        }

        $override = tempnam(sys_get_temp_dir(), 'ffb') . '.phtml';
        file_put_contents($override, '<div class="themed-open">');
        try {
            $html = $this->render('text', [], 1, ['Furan_FormBuilder::item/_open.phtml' => $override]);
        } finally {
            unlink($override);
        }
        $this->assertStringStartsWith('<div class="themed-open">', $html);
        $this->assertStringNotContainsString('class="ffb-label"', $html);
    }

    public function testItFallsBackToTheGlobalExtensionAllowlistForAccept(): void
    {
        $accept = fn (array $settings): string => $this->xpath($this->render('file', ['settings' => $settings]))
            ->query('/html/body//input')->item(0)->getAttribute('accept');

        $this->assertSame('.pdf,.png,.jpg', $accept([]));
        $this->assertSame('.pdf,.png,.jpg', $accept(['extensions' => []]));
        $this->assertSame('.pdf', $accept(['extensions' => ['pdf', 'exe']]));
        $this->assertSame('', $accept(['extensions' => ['exe', 'php']]));
        $this->assertSame('.pdf,.png', $accept(['extensions' => [' .PDF ', 'png', 'p<n>g']]));
        $this->assertSame('.pdf,.png,.jpg', $accept(['extensions' => ['<b>', ' ', ['pdf']]]));
        $this->assertFalse($this->xpath($this->render('file', ['settings' => ['extensions' => ['exe']]]))
            ->query('/html/body//input')->item(0)->hasAttribute('accept'));
    }

    public function testItPrefixesIdsWithTheInstanceNumberFromTheSecondRenderOn(): void
    {
        $xpath = $this->xpath($this->render('text', ['help' => 'h'], 2));
        $this->assertSame('ffb-7-2-first_name', $xpath->query('/html/body//input')->item(0)->getAttribute('id'));
        $this->assertSame('ffb-7-2-first_name', $xpath->query('/html/body//label')->item(0)->getAttribute('for'));
        $this->assertSame('ffb-7-2-first_name-help ffb-7-2-first_name-error', $xpath->query('/html/body//input')->item(0)->getAttribute('aria-describedby'));
    }

    /** @return string[] */
    private function optionValues(DOMXPath $xpath): array
    {
        $values = [];
        foreach ($xpath->query('/html/body//option') as $option) {
            $values[] = $option->getAttribute('value');
        }
        return $values;
    }

    private function render(string $type, array $overrides, int $instance = 1, array $stubs = []): string
    {
        $item = $overrides + [
            'id' => 'f_test01', 'kind' => 'field', 'type' => $type, 'name' => 'first_name', 'label' => 'Label',
            'placeholder' => '', 'help' => '', 'default' => '', 'width' => 'full', 'required' => false,
            'rules' => [], 'options' => [], 'settings' => [], 'autocomplete' => '', 'css_class' => '',
        ];
        $block = new FakeItemBlock(['item' => $item, 'form_id' => 7, 'parent_block' => new FakeFormParent($instance, ['pdf', 'png', 'jpg'], self::MAX_LENGTHS)], $stubs);

        return $block->render('Furan_FormBuilder::item/' . $type . '.phtml');
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        return new DOMXPath($doc);
    }

    public function testItRendersTheMonthAndSuggestInputs(): void
    {
        $xpath = $this->xpath($this->render('month', []));
        $this->assertSame('month', $xpath->query('/html/body//input')->item(0)->getAttribute('type'));

        $xpath = $this->xpath($this->render('suggest', ['options' => [['label' => 'Red', 'value' => 'r'], ['label' => 'Blue', 'value' => 'b']]]));
        $input = $xpath->query('/html/body//input')->item(0);
        $this->assertSame('ffb-7-first_name-list', $input->getAttribute('list'));
        $values = [];
        foreach ($xpath->query('/html/body//datalist[@id="ffb-7-first_name-list"]/option') as $option) {
            $values[] = $option->getAttribute('value');
        }
        $this->assertSame(['Red', 'Blue'], $values);
    }

    public function testItWrapsTextAndNumberInputsWithAffixesOnlyWhenSet(): void
    {
        foreach (['text', 'number'] as $type) {
            $xpath = $this->xpath($this->render($type, ['settings' => ['prefix' => '$', 'suffix' => 'USD']]));
            $this->assertSame('$', $xpath->query('/html/body/div/div[@class="ffb-input-group"]/span[contains(@class, "ffb-affix")]')->item(0)->textContent, $type);
            $this->assertSame('USD', $xpath->query('/html/body/div/div[@class="ffb-input-group"]/span[contains(@class, "ffb-affix")]')->item(1)->textContent, $type);
            $input = $xpath->query('/html/body/div/div[@class="ffb-input-group"]/input')->item(0);
            $this->assertSame('ffb-7-first_name', $input->getAttribute('id'), $type);
            $this->assertSame('ffb-7-first_name', $xpath->query('/html/body/div/label')->item(0)->getAttribute('for'), $type);

            $plain = $this->xpath($this->render($type, ['settings' => ['prefix' => '', 'suffix' => '']]));
            $this->assertSame(0, $plain->query('//*[contains(@class, "ffb-input-group") or contains(@class, "ffb-affix")]')->length, $type);
        }
    }

    public function testItRendersTheToggleAsASwitchCheckbox(): void
    {
        $xpath = $this->xpath($this->render('toggle', ['settings' => ['on_value' => 'on']]));
        $input = $xpath->query('/html/body/div/input')->item(0);
        $this->assertSame('checkbox', $input->getAttribute('type'));
        $this->assertSame('switch', $input->getAttribute('role'));
        $this->assertSame('on', $input->getAttribute('value'));
        $this->assertSame('fields[first_name]', $input->getAttribute('name'));
    }

    public function testItRendersConsentWithDetailsAndOnlySafePrivacyLinks(): void
    {
        $settings = ['details' => 'We store this.', 'privacy_url' => '/privacy', 'privacy_label' => 'Read more'];
        $xpath = $this->xpath($this->render('consent', ['required' => true, 'settings' => $settings]));
        $input = $xpath->query('/html/body/div/input')->item(0);
        $this->assertSame('yes', $input->getAttribute('value'));
        $this->assertSame('ffb-7-first_name-details ffb-7-first_name-error', $input->getAttribute('aria-describedby'));
        $this->assertSame('We store this.', $xpath->query('/html/body/div/small[@id="ffb-7-first_name-details"]')->item(0)->textContent);
        $link = $xpath->query('/html/body/div/a[@class="ffb-consent-link"]')->item(0);
        $this->assertSame('/privacy', $link->getAttribute('href'));
        $this->assertSame('Read more', $link->textContent);

        $xpath = $this->xpath($this->render('consent', ['settings' => ['privacy_url' => 'https://SHOP.test/privacy'] + $settings]));
        $this->assertSame(1, $xpath->query('//a')->length);

        $unsafe = ['javascript:alert(1)', '//evil.test/x', 'data:text/html,x', '/\\evil.test/x', 'https://evil.test/privacy',
            'https://shop.test@evil.test/', '/privacy page'];
        foreach ($unsafe as $url) {
            $xpath = $this->xpath($this->render('consent', ['settings' => ['privacy_url' => $url] + $settings]));
            $this->assertSame(0, $xpath->query('//a')->length, $url);
        }
    }

    public function testItRendersTheScaleAsNumberedRadiosWithEndLabels(): void
    {
        $xpath = $this->xpath($this->render('scale', [
            'required' => true, 'default' => '3',
            'settings' => ['min' => 0, 'max' => 10, 'low_label' => 'Unlikely', 'high_label' => 'Likely'],
        ]));
        $inputs = $xpath->query('/html/body/fieldset//input[@type="radio"]');
        $this->assertSame(11, $inputs->length);
        $this->assertSame('0', $inputs->item(0)->getAttribute('value'));
        $this->assertSame('ffb-7-first_name-0', $inputs->item(0)->getAttribute('id'));
        $this->assertSame('ffb-7-first_name-10', $inputs->item(10)->getAttribute('id'));
        $this->assertTrue($inputs->item(3)->hasAttribute('checked'));
        $this->assertSame('Unlikely', $xpath->query('//span[contains(@class, "ffb-scale-low")]')->item(0)->textContent);
        $this->assertSame('Likely', $xpath->query('//span[contains(@class, "ffb-scale-high")]')->item(0)->textContent);
    }

    public function testItRendersCardsWithOptionalDescriptions(): void
    {
        $xpath = $this->xpath($this->render('cards', ['options' => [
            ['label' => 'Basic', 'value' => 'basic', 'description' => 'For starters'],
            ['label' => 'Pro', 'value' => 'pro'],
        ]]));
        $this->assertSame(2, $xpath->query('/html/body/fieldset//label[contains(@class, "ffb-card")]/input[@type="radio"]')->length);
        $descriptions = $xpath->query('//span[@class="ffb-card-description"]');
        $this->assertSame(1, $descriptions->length);
        $this->assertSame('For starters', $descriptions->item(0)->textContent);
    }

    public function testItRendersTheMatrixAsATableOfRadioRows(): void
    {
        $xpath = $this->xpath($this->render('matrix', [
            'required' => true,
            'options' => [['label' => 'Good', 'value' => 'g'], ['label' => 'Bad', 'value' => 'b']],
            'settings' => ['rows' => ['Speed', 'Price'], 'require_all' => true],
        ]));
        $this->assertSame(2, $xpath->query('/html/body/fieldset//th[@scope="col"]')->length);
        $this->assertSame(2, $xpath->query('/html/body/fieldset//th[@scope="row"]')->length);
        $radios = $xpath->query('/html/body/fieldset//input[@type="radio"]');
        $this->assertSame(4, $radios->length);
        $this->assertSame('fields[first_name][0]', $radios->item(0)->getAttribute('name'));
        $this->assertSame('fields[first_name][1]', $radios->item(2)->getAttribute('name'));
        $this->assertSame('b', $radios->item(1)->getAttribute('value'));
        $this->assertTrue($radios->item(0)->hasAttribute('required'));
        $this->assertSame('ffb-7-first_name-r0 ffb-7-first_name-c0', $radios->item(0)->getAttribute('aria-labelledby'));
        $cellLabel = $xpath->query('/html/body/fieldset/div[@class="ffb-matrix-wrap"]/table//td/label[@class="ffb-matrix-cell-label"]');
        $this->assertSame(4, $cellLabel->length, 'narrow screens show each choice with its column');
        $this->assertSame('Bad', $cellLabel->item(1)->textContent);
        $this->assertSame($radios->item(1)->getAttribute('id'), $cellLabel->item(1)->getAttribute('for'));

        $loose = $this->xpath($this->render('matrix', [
            'required' => true, 'options' => [['label' => 'Good', 'value' => 'g']],
            'settings' => ['rows' => ['Speed'], 'require_all' => false],
        ]));
        $this->assertFalse($loose->query('//input[@type="radio"]')->item(0)->hasAttribute('required'));
    }

    public function testItAlwaysLimitsTypedTextToWhatTheServerKeeps(): void
    {
        foreach (self::MAX_LENGTHS as $type => $cap) {
            $html = $this->render($type, []);
            $control = $this->xpath($html)->query('/html/body//input[not(@type="hidden")] | /html/body//textarea')->item(0);
            $this->assertSame((string)$cap, $control->getAttribute('maxlength'), $type);
        }
        foreach (['text' => 1000, 'textarea' => 20000] as $type => $cap) {
            $tooLong = [['rule' => 'max_length', 'value' => $cap + 1, 'message' => '']];
            $control = $this->xpath($this->render($type, ['rules' => $tooLong]))->query('/html/body//input | /html/body//textarea')->item(0);
            $this->assertSame((string)$cap, $control->getAttribute('maxlength'), $type);
        }
        $this->assertFalse($this->xpath($this->render('number', []))->query('/html/body//input')->item(0)->hasAttribute('maxlength'));
    }

    public function testItLimitsEveryTypedAddressPartToWhatTheServerKeeps(): void
    {
        $xpath = $this->xpath($this->render('address', ['render' => ['street_lines' => 3]]));
        $parts = $xpath->query('/html/body//div[contains(@class, "ffb-address")]//input[@type="text"]');
        $this->assertSame(6, $parts->length);
        foreach ($parts as $input) {
            $this->assertSame('255', $input->getAttribute('maxlength'), $input->getAttribute('name'));
        }
        $this->assertFalse($xpath->query('//select[@data-ffb-region-list]')->item(0)->hasAttribute('maxlength'));
    }

    public function testItRendersAColourAsAPostedHiddenValueWithAnUnnamedPickerAndHexBox(): void
    {
        $xpath = $this->xpath($this->render('color', ['required' => true]));
        $box = $xpath->query('/html/body/div/div[@class="ffb-color"]')->item(0);
        $this->assertTrue($box->hasAttribute('data-ffb-color'));
        $this->assertTrue($box->hasAttribute('data-ffb-empty'));
        $this->assertFalse($box->hasAttribute('data-ffb-default'));
        $value = $xpath->query('/html/body/div/div/input[@type="hidden"]')->item(0);
        $this->assertSame('fields[first_name]', $value->getAttribute('name'));
        $this->assertSame('', $value->getAttribute('value'));
        $this->assertTrue($value->hasAttribute('data-ffb-color-value'));
        $picker = $xpath->query('/html/body/div/div/input[@type="color"]')->item(0);
        $this->assertFalse($picker->hasAttribute('name'));
        $this->assertSame('-1', $picker->getAttribute('tabindex'));
        $this->assertSame('true', $picker->getAttribute('aria-hidden'));
        $hex = $xpath->query('/html/body/div/div/input[@type="text"]')->item(0);
        $this->assertSame('ffb-7-first_name', $hex->getAttribute('id'));
        $this->assertSame('ffb-7-first_name', $xpath->query('/html/body/div/label')->item(0)->getAttribute('for'));
        $this->assertFalse($hex->hasAttribute('name'));
        $this->assertSame('ffb-input ffb-color-hex', $hex->getAttribute('class'));
        $this->assertSame('7', $hex->getAttribute('maxlength'));
        $this->assertSame('#rrggbb', $hex->getAttribute('placeholder'));
        $this->assertTrue($hex->hasAttribute('data-ffb-required-part'));
        $this->assertTrue($hex->hasAttribute('required'));
        $this->assertSame('ffb-7-first_name-error', $hex->getAttribute('aria-describedby'));
        $clear = $xpath->query('/html/body/div/div/button[@data-ffb-color-clear]')->item(0);
        $this->assertSame('button', $clear->getAttribute('type'));
        $this->assertSame('Clear colour', $clear->getAttribute('aria-label'));
        $this->assertSame('Clear', $clear->textContent);
        $this->assertTrue($clear->hasAttribute('hidden'));

        $xpath = $this->xpath($this->render('color', ['default' => '#A1B2C3']));
        $box = $xpath->query('/html/body/div/div[@class="ffb-color"]')->item(0);
        $this->assertFalse($box->hasAttribute('data-ffb-empty'));
        $this->assertSame('#a1b2c3', $box->getAttribute('data-ffb-default'));
        $this->assertSame('#a1b2c3', $xpath->query('//input[@type="hidden"]')->item(0)->getAttribute('value'));
        $this->assertSame('#a1b2c3', $xpath->query('//input[@type="color"]')->item(0)->getAttribute('value'));
        $this->assertSame('#a1b2c3', $xpath->query('//input[@type="text"]')->item(0)->getAttribute('value'));
        $this->assertFalse($xpath->query('//button[@data-ffb-color-clear]')->item(0)->hasAttribute('hidden'));

        $xpath = $this->xpath($this->render('color', ['default' => 'red"><b>']));
        $this->assertSame('', $xpath->query('//input[@type="hidden"]')->item(0)->getAttribute('value'));
        $this->assertSame(0, $xpath->query('//b')->length);
    }

    public function testItGivesRatingAndScaleTheirReviewStepWording(): void
    {
        $rating = $this->xpath($this->render('rating', ['settings' => ['max' => 7]]))->query('//div[@class="ffb-rating"]')->item(0);
        $this->assertSame('%1 of 7', $rating->getAttribute('data-ffb-summary-text'));
        $scale = $this->xpath($this->render('scale', ['settings' => ['min' => 0, 'max' => 10]]))->query('//div[@class="ffb-scale"]')->item(0);
        $this->assertSame('%1 on a 0 to 10 scale', $scale->getAttribute('data-ffb-summary-text'));
    }

    public function testItRendersTheListAsOneArrayNamedRowWithAddAndRemoveButtons(): void
    {
        $xpath = $this->xpath($this->render('list', [
            'required' => true,
            'placeholder' => '<b>Name</b>',
            'settings' => ['max_items' => 99, 'add_label' => '<i>More</i>'],
        ]));
        $box = $xpath->query('/html/body/fieldset//div[@data-ffb-list]')->item(0);
        $this->assertSame('20', $box->getAttribute('data-ffb-list-max'));
        $inputs = $xpath->query('//li[@data-ffb-list-row]/input');
        $this->assertSame(1, $inputs->length);
        $input = $inputs->item(0);
        $this->assertSame('fields[first_name][]', $input->getAttribute('name'));
        $this->assertSame('ffb-7-first_name', $input->getAttribute('id'));
        $this->assertSame('<b>Name</b>', $input->getAttribute('placeholder'));
        $this->assertFalse($input->hasAttribute('required'));
        $this->assertSame('button', $xpath->query('//button[@data-ffb-list-remove]')->item(0)->getAttribute('type'));
        $this->assertSame('<i>More</i>', $xpath->query('//button[@data-ffb-list-add]')->item(0)->textContent);
    }

    public function testItRendersTwoLabelledDateInputsWithMinAndMaxAttributes(): void
    {
        $xpath = $this->xpath($this->render('date_range', [
            'required' => true,
            'settings' => ['min_date' => '2026-01-01', 'max_date' => '2026-12-31', 'start_label' => 'Arrive', 'end_label' => 'Leave'],
        ]));
        $wrapper = $xpath->query('/html/body/fieldset')->item(0);
        $this->assertSame('date_range', $wrapper->getAttribute('data-ffb-type'));
        $inputs = $xpath->query('/html/body/fieldset//input[@type="date"]');
        $this->assertSame(2, $inputs->length);
        $this->assertSame('fields[first_name][start]', $inputs->item(0)->getAttribute('name'));
        $this->assertSame('fields[first_name][end]', $inputs->item(1)->getAttribute('name'));
        $this->assertSame('ffb-7-first_name', $inputs->item(0)->getAttribute('id'));
        $this->assertSame('ffb-7-first_name-end', $inputs->item(1)->getAttribute('id'));
        foreach ($inputs as $input) {
            $this->assertSame('2026-01-01', $input->getAttribute('min'));
            $this->assertSame('2026-12-31', $input->getAttribute('max'));
            $this->assertTrue($input->hasAttribute('required'));
        }
        $labels = $xpath->query('/html/body/fieldset//label');
        $this->assertSame('Arrive', $labels->item(0)->textContent);
        $this->assertSame('ffb-7-first_name', $labels->item(0)->getAttribute('for'));
        $this->assertSame('Leave', $labels->item(1)->textContent);
        $this->assertSame('ffb-7-first_name-end', $labels->item(1)->getAttribute('for'));

        $plain = $this->xpath($this->render('date_range', []));
        $this->assertFalse($plain->query('//input[@type="date"]')->item(0)->hasAttribute('min'));
        $this->assertFalse($plain->query('//input[@type="date"]')->item(0)->hasAttribute('required'));
        $this->assertSame('Start date', $plain->query('//label')->item(0)->textContent);
    }

    public function testItEscapesLabelsAndHelpInTheDateRangeTemplate(): void
    {
        $html = $this->render('date_range', [
            'label' => '<b>Stay</b>', 'help' => '<i>Help</i>',
            'settings' => ['start_label' => '<u>From</u>', 'end_label' => '"><script>x</script>'],
        ]);
        $this->assertStringNotContainsString('<b>Stay', $html);
        $this->assertStringNotContainsString('<i>Help', $html);
        $this->assertStringNotContainsString('<u>From', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $xpath = $this->xpath($html);
        $this->assertSame('<i>Help</i>', $xpath->query('//p[@class="ffb-help"]')->item(0)->textContent);
        $this->assertSame('<u>From</u>', $xpath->query('//fieldset//label')->item(0)->textContent);
    }

    public function testItEscapesAddressLabelsInTheTemplate(): void
    {
        $evil = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';
        $html = $this->render('address', [
            'label' => $evil, 'help' => $evil, 'css_class' => $evil, 'name' => 'a"b', 'required' => true,
            'render' => ['street_lines' => 3],
        ]);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString($evil, $html);

        $xpath = $this->xpath($this->render('address', ['label' => '<b>Home</b>', 'help' => '<i>H</i>', 'render' => ['street_lines' => 3]]));
        $this->assertSame('<b>Home</b>', $xpath->query('//fieldset/legend')->item(0)->textContent);
        $this->assertSame('<i>H</i>', $xpath->query('//p[@class="ffb-help"]')->item(0)->textContent);
    }

    public function testItFlagsTheAddressPartsTheServerRequires(): void
    {
        $xpath = $this->xpath($this->render('address', ['required' => true, 'render' => ['street_lines' => 3]]));
        $flagged = [];
        foreach ($xpath->query('//*[@data-ffb-required-part]') as $node) {
            $flagged[] = $node->getAttribute('name');
        }
        $this->assertSame([
            'fields[first_name][country_id]', 'fields[first_name][street][]', 'fields[first_name][city]', 'fields[first_name][postcode]',
        ], $flagged);
    }

    public function testItRendersTheAddressControlsWithPostedNamesAndHooks(): void
    {
        $xpath = $this->xpath($this->render('address', ['required' => true, 'render' => ['street_lines' => 3]]));
        $this->assertSame('address', $xpath->query('/html/body/fieldset')->item(0)->getAttribute('data-ffb-type'));
        $name = static fn (string $part) => $xpath->query('//*[@name="fields[first_name]' . $part . '"]');

        $country = $name('[country_id]')->item(0);
        $this->assertSame('select', $country->nodeName);
        $this->assertSame('ffb-7-first_name', $country->getAttribute('id'));
        $this->assertSame(['', 'GB', 'US'], array_map(
            static fn ($o) => $o->getAttribute('value'),
            iterator_to_array($xpath->query('.//option', $country))
        ));
        $this->assertSame(3, $name('[street][]')->length);
        $this->assertTrue($name('[street][]')->item(0)->hasAttribute('required'));
        $this->assertFalse($name('[street][]')->item(1)->hasAttribute('required'));
        $this->assertSame(1, $name('[city]')->length);
        $this->assertSame(1, $name('[postcode]')->length);

        $list = $name('[region_id]')->item(0);
        $this->assertSame('select', $list->nodeName);
        $this->assertTrue($list->hasAttribute('hidden'));
        $text = $name('[region]')->item(0);
        $this->assertFalse($text->hasAttribute('hidden'));

        $hint = $xpath->query('//p[@data-ffb-hint]')->item(0);
        $this->assertSame('polite', $hint->getAttribute('aria-live'));
        $this->assertSame('ffb-hint', $hint->getAttribute('class'));
        $this->assertFalse($hint->hasAttribute('data-ffb-error'));
        $this->assertStringContainsString($hint->getAttribute('id'), $name('[postcode]')->item(0)->getAttribute('aria-describedby'));

        $this->assertSame(2, $this->xpath($this->render('address', []))->query('//input[@name="fields[first_name][street][]"]')->length);
    }

    public function testItEscapesLabelsAndDataAttributesInTheMapTemplate(): void
    {
        $evil = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';
        $html = $this->render('map', [
            'label' => $evil, 'help' => $evil, 'css_class' => $evil, 'name' => 'a"b', 'required' => true,
            'settings' => ['default_lat' => $evil, 'default_lng' => $evil, 'default_zoom' => $evil, 'height' => $evil],
        ]);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString($evil, $html);

        $xpath = $this->xpath($this->render('map', ['label' => '<b>Pin</b>', 'help' => '<i>H</i>']));
        $this->assertSame('<b>Pin</b>', $xpath->query('//fieldset/legend')->item(0)->textContent);
        $this->assertSame('<i>H</i>', $xpath->query('//p[@class="ffb-help"]')->item(0)->textContent);
    }

    public function testItRendersTheMapPlaceholderAndCoordinateInputs(): void
    {
        $xpath = $this->xpath($this->render('map', [
            'required' => true, 'settings' => ['default_lat' => 48.85, 'default_lng' => 2.35, 'default_zoom' => 9, 'height' => 400],
        ]));
        $this->assertSame('map', $xpath->query('/html/body/fieldset')->item(0)->getAttribute('data-ffb-type'));

        $map = $xpath->query('//*[@data-ffb-map]')->item(0);
        $this->assertSame('48.85', $map->getAttribute('data-center-lat'));
        $this->assertSame('2.35', $map->getAttribute('data-center-lng'));
        $this->assertSame('9', $map->getAttribute('data-zoom'));
        $this->assertStringContainsString('400px', $map->getAttribute('style'));

        $this->assertSame(1, $xpath->query('//*[@data-ffb-map-placeholder]//button[@type="button"][@data-ffb-map-load]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-ffb-map-placeholder]//*[@data-ffb-map-notice]')->length);
        $this->assertTrue($xpath->query('//*[@data-ffb-map-canvas]')->item(0)->hasAttribute('hidden'));
        $this->assertSame('0123abcd0123abcd0123abcd0123abcd', $xpath->query('//*[@data-ffb-map-canvas]')->item(0)->getAttribute('data-ffb-map-canvas'));
        $this->assertTrue($xpath->query('//button[@data-ffb-map-centre]')->item(0)->hasAttribute('hidden'));
        $this->assertSame('polite', $xpath->query('//*[@data-ffb-map-status]')->item(0)->getAttribute('aria-live'));

        $lat = $xpath->query('//input[@data-ffb-map-lat]')->item(0);
        $this->assertSame('fields[first_name][lat]', $lat->getAttribute('name'));
        $this->assertSame('ffb-7-first_name', $lat->getAttribute('id'));
        $this->assertTrue($lat->hasAttribute('required'));
        $lng = $xpath->query('//input[@data-ffb-map-lng]')->item(0);
        $this->assertSame('fields[first_name][lng]', $lng->getAttribute('name'));
        $this->assertSame('hidden', $xpath->query('//input[@name="fields[first_name][zoom]"]')->item(0)->getAttribute('type'));
        $this->assertSame('hidden', $xpath->query('//input[@name="fields[first_name][label]"]')->item(0)->getAttribute('type'));
        $this->assertStringContainsString('ffb-7-first_name-error', $lat->getAttribute('aria-describedby'));
        $this->assertSame(0, $xpath->query('//script | //link | //img | //iframe')->length);

        $defaults = $this->xpath($this->render('map', []))->query('//*[@data-ffb-map]')->item(0);
        $this->assertSame('51.5', $defaults->getAttribute('data-center-lat'));
        $this->assertSame('0', $defaults->getAttribute('data-center-lng'));
        $this->assertSame('5', $defaults->getAttribute('data-zoom'));
        $this->assertStringContainsString('320px', $defaults->getAttribute('style'));
    }
    public function testItNeverRendersANewsletterFieldPreTicked(): void
    {
        $xpath = $this->xpath($this->render('newsletter', [
            'default' => 'yes', 'required' => true, 'settings' => ['checked_value' => 'no', 'default' => 'yes'],
        ]));

        $input = $xpath->query('/html/body/div/input')->item(0);
        $this->assertSame('checkbox', $input->getAttribute('type'));
        $this->assertSame('yes', $input->getAttribute('value'));
        $this->assertFalse($input->hasAttribute('checked'));
        $this->assertFalse($input->hasAttribute('required'));
        $this->assertSame(0, $xpath->query('/html/body//*[@class="ffb-req"]')->length);
    }

    public function testItMarksTheNewsletterBoxAndNoteForTheImpersonationLock(): void
    {
        $xpath = $this->xpath($this->render('newsletter', []));

        $this->assertTrue($xpath->query('/html/body/div/input')->item(0)->hasAttribute('data-ffb-impersonation-lock'));
        $note = $xpath->query('/html/body/div/p[@data-ffb-lock-note]')->item(0);
        $this->assertTrue($note->hasAttribute('hidden'));
        $this->assertStringContainsString('logged in as this customer', $note->textContent);
    }
}
