<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Schema;

use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\FieldType\Checkboxes;
use Furan\FormBuilder\Model\FieldType\Color;
use Furan\FormBuilder\Model\FieldType\Country;
use Furan\FormBuilder\Model\FieldType\Date;
use Furan\FormBuilder\Model\FieldType\DateRange;
use Furan\FormBuilder\Model\FieldType\DateTime;
use Furan\FormBuilder\Model\FieldType\File;
use Furan\FormBuilder\Model\FieldType\Hidden;
use Furan\FormBuilder\Model\FieldType\Month;
use Furan\FormBuilder\Model\FieldType\Multiselect;
use Furan\FormBuilder\Model\FieldType\Number;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Radio;
use Furan\FormBuilder\Model\FieldType\Range;
use Furan\FormBuilder\Model\FieldType\Rating;
use Furan\FormBuilder\Model\FieldType\Scale;
use Furan\FormBuilder\Model\FieldType\Select;
use Furan\FormBuilder\Model\FieldType\Suggest;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\FieldType\Textarea;
use Furan\FormBuilder\Model\FieldType\Time;
use Furan\FormBuilder\Model\Prefill\Sources;
use Furan\FormBuilder\Model\Schema\FieldChecks;
use Furan\FormBuilder\Model\Schema\Normalizer;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Schema\SettingValues;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use Furan\FormBuilder\Model\Validation\PatternMatcher;
use Furan\FormBuilder\Model\Validation\RepeatAnalyzer;
use Furan\FormBuilder\Model\Validation\Rule\Integer;
use Furan\FormBuilder\Model\Validation\Rule\Max;
use Furan\FormBuilder\Model\Validation\Rule\MaxDate;
use Furan\FormBuilder\Model\Validation\Rule\MaxLength;
use Furan\FormBuilder\Model\Validation\Rule\MaxSelected;
use Furan\FormBuilder\Model\Validation\Rule\Min;
use Furan\FormBuilder\Model\Validation\Rule\MinDate;
use Furan\FormBuilder\Model\Validation\Rule\MinLength;
use Furan\FormBuilder\Model\Validation\Rule\MinSelected;
use Furan\FormBuilder\Model\Validation\Rule\Pattern;
use Furan\FormBuilder\Model\Validation\RulePool;
use Furan\FormBuilder\Test\Unit\Helper\ConditionNormalizers;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Magento\Directory\Model\AllowedCountries;
use Magento\Framework\Locale\ListsInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class NormalizerFieldChecksTest extends TestCase
{
    private Normalizer $normalizer;

    protected function setUp(): void
    {
        $matcher = new PatternMatcher(new RepeatAnalyzer());
        $types = new Pool([
            'text' => new Text(), 'textarea' => new Textarea(), 'hidden' => new Hidden(), 'file' => new File(),
            'select' => new Select(), 'radio' => new Radio(), 'checkboxes' => new Checkboxes(),
            'multiselect' => new Multiselect(), 'suggest' => new Suggest(), 'number' => new Number(),
            'range' => new Range(), 'date' => new Date(), 'datetime' => new DateTime(), 'month' => new Month(),
            'time' => new Time(), 'color' => new Color(), 'scale' => new Scale(), 'rating' => new Rating(),
            'date_range' => new DateRange(), 'country' => $this->country(),
        ]);
        $rules = new RulePool([
            'min' => new Min(), 'max' => new Max(), 'integer' => new Integer(),
            'min_length' => new MinLength(), 'max_length' => new MaxLength(), 'pattern' => new Pattern($matcher),
            'min_date' => new MinDate(), 'max_date' => new MaxDate(),
            'min_selected' => new MinSelected(), 'max_selected' => new MaxSelected(),
        ]);
        $config = $this->createStub(Config::class);
        $config->method('getAllowedExtensions')->willReturn(['pdf', 'jpg', 'png']);
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn([]);
        $this->normalizer = new Normalizer(
            $types,
            $rules,
            $matcher,
            new SettingValues(new RedirectValidator($manager)),
            new PersonalData(FieldPools::builtIn()),
            new Sources(),
            ConditionNormalizers::builtIn([], $types),
            new FieldChecks($rules, $config, new \Furan\FormBuilder\Model\FieldType\SettingLabels(), new \Furan\FormBuilder\Model\Upload\ExtensionList()),
        new \Furan\FormBuilder\Model\Validation\PresetFit()
);
    }

    private function country(): Country
    {
        $lists = $this->createStub(ListsInterface::class);
        $lists->method('getOptionCountries')->willReturn([['value' => 'GB', 'label' => 'United Kingdom'], ['value' => 'FR', 'label' => 'France']]);
        return new Country($this->createStub(AllowedCountries::class), $lists, $this->createStub(\Magento\Framework\Locale\ResolverInterface::class));
    }

    private function field(array $over): array
    {
        return $over + ['id' => 'f_abcd12', 'kind' => 'field', 'type' => 'text', 'name' => 'answer', 'label' => 'Answer'];
    }

    private function options(string ...$labels): array
    {
        return array_map(static fn (string $l): array => ['label' => $l, 'value' => strtolower($l)], $labels);
    }

    /** @return array<string, mixed> the normalised field */
    private function saved(array $field): array
    {
        return $this->normalizer->normalize(['pages' => [['items' => [$this->field($field)]]]])['pages'][0]['items'][0];
    }

    /** @return string[] */
    private function errors(array ...$fields): array
    {
        try {
            $this->normalizer->normalize(['pages' => [['items' => array_map($this->field(...), $fields)]]]);
        } catch (InvalidSchemaException $e) {
            return $e->getErrors();
        }
        return [];
    }

    public function testItClearsTheDefaultOfATypeThatHasNoDefaultControl(): void
    {
        $this->assertSame('', $this->saved(['type' => 'file', 'default' => 'anything'])['default']);
        $this->assertSame('', $this->saved(['type' => 'date_range', 'default' => '2025-01-01'])['default']);
    }

    public function testItReportsEveryCaseOfTheSharedSaveChecksFixture(): void
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/field-save-checks.json'), true);
        foreach ($cases as $case) {
            $this->assertSame($case['errors'], $this->errors($case['field']), $case['name']);
        }
    }

    public function testItNormalisesFileExtensionsToLowercaseWithoutDotsOrDuplicates(): void
    {
        $saved = $this->saved(['type' => 'file', 'settings' => ['extensions' => [' .PDF', 'pdf', '..Jpg', '']]]);

        $this->assertSame(['pdf', 'jpg'], $saved['settings']['extensions']);
    }

    public function testItRefusesFileExtensionsTheStoreUploadAllowlistDoesNotAllow(): void
    {
        $this->assertSame(
            ['Field "Answer": these file types are not allowed by the store\'s upload settings: exe, p d f, svg.'],
            $this->errors(['type' => 'file', 'settings' => ['extensions' => ['pdf', 'EXE', 'p d f', '.svg', 'exe']]])
        );
    }

    public function testItRefusesRequiredOnAServerValueField(): void
    {
        $this->assertSame(
            ['Field "Answer" cannot be required: its value is set by the server, not the visitor.'],
            $this->errors(['type' => 'hidden', 'required' => true, 'default' => 'x'])
        );
    }

    public function testItRefusesADefaultThatIsNotAnOptionValue(): void
    {
        $this->assertSame(
            ['Field "Answer": the default value "Red" is not one of its options.'],
            $this->errors(['type' => 'select', 'options' => $this->options('Red', 'Blue'), 'default' => 'Red'])
        );
        $this->assertSame('red', $this->saved(['type' => 'radio', 'options' => $this->options('Red'), 'default' => 'red'])['default']);
    }

    public function testItAcceptsOneOptionValueAsTheDefaultOfAMultiValueField(): void
    {
        foreach (['checkboxes', 'multiselect'] as $type) {
            $field = ['type' => $type, 'options' => $this->options('Red', 'Blue'), 'default' => 'blue',
                'rules' => [['rule' => 'min_selected', 'value' => 2]]];
            $this->assertSame('blue', $this->saved($field)['default'], $type);
            $this->assertSame(
                ['Field "Answer": the default value "red,blue" is not one of its options.'],
                $this->errors(['default' => 'red,blue'] + $field),
                $type
            );
        }
    }

    public function testItRefusesADefaultLongerThanTheTypeKeeps(): void
    {
        $this->assertSame(
            ['Field "Answer": the default value is longer than 255 characters.'],
            $this->errors(['type' => 'hidden', 'default' => str_repeat('a', 256)])
        );
        $this->assertSame(
            ['Field "Answer": the default value is longer than 1000 characters.'],
            $this->errors(['type' => 'textarea', 'default' => str_repeat('a', 1001)])
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidDefaults(): array
    {
        return [
            'colour' => [['type' => 'color'], 'red'],
            'range above max' => [['type' => 'range', 'settings' => ['min' => 0, 'max' => 10, 'step' => 1]], '11'],
            'range off step' => [['type' => 'range', 'settings' => ['min' => 0, 'max' => 10, 'step' => 2]], '3'],
            'number' => [['type' => 'number'], 'ten'],
            'number off step' => [['type' => 'number', 'settings' => ['step' => '0.5']], '1.25'],
            'date' => [['type' => 'date'], '01/02/2024'],
            'datetime' => [['type' => 'datetime'], '2024-01-02 10:00'],
            'month' => [['type' => 'month'], '2024-13'],
            'time' => [['type' => 'time'], '25:00'],
            'scale' => [['type' => 'scale', 'settings' => ['min' => 1, 'max' => 5]], '6'],
            'rating' => [['type' => 'rating', 'settings' => ['max' => 5]], '0'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDefaults')]
    public function testItRefusesADefaultTheTypeWouldReject(array $field, string $default): void
    {
        $this->assertSame(
            [sprintf('Field "Answer": the default value "%s" is not valid for this field.', $default)],
            $this->errors(['default' => $default] + $field)
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function validDefaults(): array
    {
        return [
            'colour' => [['type' => 'color'], '#1d5fd1'],
            'range' => [['type' => 'range', 'settings' => ['min' => 0, 'max' => 10, 'step' => 2]], '4'],
            'number' => [['type' => 'number', 'settings' => ['step' => '0.5']], '1.5'],
            'date' => [['type' => 'date'], '2024-02-29'],
            'datetime' => [['type' => 'datetime'], '2024-01-02T10:00'],
            'month' => [['type' => 'month'], '2024-12'],
            'time' => [['type' => 'time'], '23:30'],
            'scale' => [['type' => 'scale', 'settings' => ['min' => 1, 'max' => 5]], '5'],
            'rating' => [['type' => 'rating', 'settings' => ['max' => 5]], '1'],
            'hidden' => [['type' => 'hidden'], str_repeat('a', 255)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validDefaults')]
    public function testItKeepsADefaultTheTypeAccepts(array $field, string $default): void
    {
        $this->assertSame($default, $this->saved(['default' => $default] + $field)['default']);
    }

    public function testItChecksACountryDefaultAgainstEveryMagentoCountryWhateverTheStore(): void
    {
        $this->assertSame('FR', $this->saved(['type' => 'country', 'default' => 'FR'])['default']);
        $this->assertSame(
            ['Field "Answer": the default value "XX" is not one of its options.'],
            $this->errors(['type' => 'country', 'default' => 'XX'])
        );
        $this->assertSame(
            ['Field "Answer": the default value "France" is not one of its options.'],
            $this->errors(['type' => 'country', 'default' => 'France'])
        );
    }

    public function testItRefusesAMaximumLengthRuleAboveWhatTheTypeKeeps(): void
    {
        $this->assertSame(
            ['Field "Answer": "Maximum length" can be at most 1000.'],
            $this->errors(['rules' => [['rule' => 'max_length', 'value' => 1001]]])
        );
        $this->assertSame([], $this->errors(['type' => 'textarea', 'rules' => [['rule' => 'max_length', 'value' => 20000]]]));
    }

    public function testItRefusesADefaultThatBreaksTheFieldsRules(): void
    {
        $this->assertSame(
            ['Field "Answer": the default value "120" breaks the rule "Maximum value".'],
            $this->errors(['type' => 'number', 'default' => '120', 'rules' => [['rule' => 'max', 'value' => 100]]])
        );
        $this->assertSame(
            ['Field "Answer": the default value "ab" breaks the rule "Minimum length".'],
            $this->errors(['default' => 'ab', 'rules' => [['rule' => 'min_length', 'value' => 3]]])
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function contradictoryRules(): array
    {
        return [
            'number' => [
                ['type' => 'number', 'rules' => [['rule' => 'min', 'value' => 10], ['rule' => 'max', 'value' => 5]]],
                'Field "Answer": "Minimum value" is greater than "Maximum value".',
            ],
            'length' => [
                ['type' => 'text', 'rules' => [['rule' => 'min_length', 'value' => 10], ['rule' => 'max_length', 'value' => 5]]],
                'Field "Answer": "Minimum length" is greater than "Maximum length".',
            ],
            'suggest length' => [
                ['type' => 'suggest', 'options' => [['label' => 'A']],
                    'rules' => [['rule' => 'min_length', 'value' => 4], ['rule' => 'max_length', 'value' => 3]]],
                'Field "Answer": "Minimum length" is greater than "Maximum length".',
            ],
            'date' => [
                ['type' => 'date', 'rules' => [['rule' => 'min_date', 'value' => '2025-01-02'], ['rule' => 'max_date', 'value' => '2025-01-01']]],
                'Field "Answer": "Earliest date" is greater than "Latest date".',
            ],
            'selected' => [
                ['type' => 'checkboxes', 'options' => [['label' => 'A'], ['label' => 'B'], ['label' => 'C']],
                    'rules' => [['rule' => 'min_selected', 'value' => 3], ['rule' => 'max_selected', 'value' => 2]]],
                'Field "Answer": "Minimum selected" is greater than "Maximum selected".',
            ],
            'selected over options' => [
                ['type' => 'multiselect', 'options' => [['label' => 'A'], ['label' => 'B']],
                    'rules' => [['rule' => 'min_selected', 'value' => 3]]],
                'Field "Answer": "Minimum selected" is more than the number of options (2).',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('contradictoryRules')]
    public function testItRefusesContradictoryRuleBounds(array $field, string $error): void
    {
        $this->assertSame([$error], $this->errors($field));
    }

    public function testItAcceptsEqualRuleBounds(): void
    {
        $this->assertSame([], $this->errors(
            ['type' => 'number', 'rules' => [['rule' => 'min', 'value' => 5], ['rule' => 'max', 'value' => 5]]],
            ['name' => 'code', 'rules' => [['rule' => 'min_length', 'value' => 4], ['rule' => 'max_length', 'value' => 4]]]
        ));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function contradictorySettings(): array
    {
        return [
            'range min over max' => [
                ['type' => 'range', 'settings' => ['min' => 10, 'max' => 5]],
                'Field "Answer": "Minimum" is greater than "Maximum".',
            ],
            'range step zero' => [
                ['type' => 'range', 'settings' => ['step' => 0]],
                'Field "Answer": "Step" must be greater than 0.',
            ],
            'date range days' => [
                ['type' => 'date_range', 'settings' => ['min_days' => 5, 'max_days' => 3]],
                'Field "Answer": "Minimum days" is greater than "Maximum days".',
            ],
            'date range dates' => [
                ['type' => 'date_range', 'settings' => ['min_date' => '2025-02-01', 'max_date' => '2025-01-01']],
                'Field "Answer": "Earliest date" is greater than "Latest date".',
            ],
            'date range malformed earliest' => [
                ['type' => 'date_range', 'settings' => ['min_date' => '2025-02-30']],
                'Field "Answer": "Earliest date" must be a date such as 2025-01-31.',
            ],
            'date range malformed latest' => [
                ['type' => 'date_range', 'settings' => ['max_date' => 'tomorrow']],
                'Field "Answer": "Latest date" must be a date such as 2025-01-31.',
            ],
            'number step' => [
                ['type' => 'number', 'settings' => ['step' => '1,5']],
                'Field "Answer": "Step" must be "any" or a number greater than 0.',
            ],
            'number step zero' => [
                ['type' => 'number', 'settings' => ['step' => '0']],
                'Field "Answer": "Step" must be "any" or a number greater than 0.',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('contradictorySettings')]
    public function testItRefusesContradictoryOrMalformedTypeSettings(array $field, string $error): void
    {
        $this->assertSame([$error], $this->errors($field));
    }

    public function testItAcceptsADateRangeWithoutAMaximumNumberOfDays(): void
    {
        $saved = $this->saved(['type' => 'date_range', 'settings' => ['min_days' => 5, 'max_days' => 0]]);

        $this->assertSame([5, 0], [$saved['settings']['min_days'], $saved['settings']['max_days']]);
    }

    public function testItAcceptsAnyOrAPositiveNumberAsTheNumberStep(): void
    {
        foreach (['', 'any', '1', '0.25'] as $step) {
            $this->assertSame([], $this->errors(['type' => 'number', 'settings' => ['step' => $step]]), $step);
        }
    }

    public function testItAcceptsAnyTextAsTheDefaultOfASuggestionField(): void
    {
        $this->assertSame('Wool hat', $this->saved([
            'type' => 'suggest', 'options' => [['label' => 'Leather boots']], 'default' => 'Wool hat',
        ])['default']);
    }

    public function testItRefusesToHideTheOptionsOfASuggestionField(): void
    {
        $this->assertSame(['"Answer": options of a suggestion field cannot be hidden.'], $this->errors(
            ['name' => 'size', 'label' => 'Size', 'id' => 'f_size01'],
            ['type' => 'suggest', 'options' => [['label' => 'Boots']], 'condition' => [
                'match' => 'all',
                'rules' => [['field' => 'size', 'operator' => 'equals', 'value' => 'x']],
                'actions' => [['action' => 'hide_options', 'param' => ['Boots']]],
            ]]
        ));
    }

    public function testItAcceptsAnyTextAsTheSetValueOfASuggestionField(): void
    {
        $this->assertSame([], $this->errors(
            ['name' => 'size', 'label' => 'Size', 'id' => 'f_size01'],
            ['type' => 'suggest', 'options' => [['label' => 'Boots']], 'condition' => [
                'match' => 'all',
                'rules' => [['field' => 'size', 'operator' => 'equals', 'value' => 'x']],
                'actions' => [['action' => 'set_value', 'param' => 'Sandals']],
            ]]
        ));
    }
}
