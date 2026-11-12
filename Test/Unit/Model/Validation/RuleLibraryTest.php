<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Validation;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\Prefill\Sources;
use Furan\FormBuilder\Model\ResourceModel\SavedRule\Collection;
use Furan\FormBuilder\Model\ResourceModel\SavedRule\CollectionFactory;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\Normalizer;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Schema\PublicSchema;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use Furan\FormBuilder\Model\Validation\BuiltInRules;
use Furan\FormBuilder\Model\Validation\PatternMatcher;
use Furan\FormBuilder\Model\Validation\RepeatAnalyzer;
use Furan\FormBuilder\Model\Validation\Rule\Preset;
use Furan\FormBuilder\Model\Validation\RuleLibrary;
use Furan\FormBuilder\Model\Validation\RulePool;
use Magento\Framework\DataObject;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class RuleLibraryTest extends TestCase
{
    private function library(array $saved = []): RuleLibrary
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getIterator')->willReturnCallback(
            static fn () => new \ArrayIterator(array_map(static fn (array $r) => new DataObject($r), $saved))
        );
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        return new RuleLibrary(new BuiltInRules(), $factory, new PatternMatcher(new RepeatAnalyzer()));
    }

    public function testEveryBuiltInRuleIsSafeAndAcceptsItsOwnExample(): void
    {
        $matcher = new PatternMatcher(new RepeatAnalyzer());
        $rules = (new BuiltInRules())->all();
        $this->assertGreaterThanOrEqual(15, count($rules));
        foreach ($rules as $code => $rule) {
            $this->assertTrue($matcher->isSafe($rule['pattern']), $code);
            $this->assertTrue($matcher->matches($rule['pattern'], $rule['example']), $code);
            $this->assertNotSame('', $rule['message'], $code);
        }
    }

    public function testBuiltInRulesRejectTypicalMistakes(): void
    {
        $matcher = new PatternMatcher(new RepeatAnalyzer());
        $rules = (new BuiltInRules())->all();
        $cases = [
            ['uk_postcode', 'sw1a1aa', true], ['uk_postcode', '12345', false],
            ['us_zip', '90210-1234', true], ['us_zip', '9021', false],
            ['uk_phone', '+44 20 7946 0958', true], ['uk_phone', '12345', false],
            ['no_links', "Line one\nline two", true], ['no_links', 'see WWW.spam.test', false],
            ['no_links', 'go to https://spam.test', false],
            ['full_name', "Zoë O'Brien-Smith", true], ['full_name', 'Ada', false],
        ];
        foreach ($cases as [$code, $value, $expected]) {
            $this->assertSame($expected, $matcher->matches($rules[$code]['pattern'], $value), $code . ': ' . $value);
        }
    }

    public function testItAcceptsExtraBuiltInRulesFromDi(): void
    {
        $rules = (new BuiltInRules(['ref' => ['label' => 'Ref', 'pattern' => '[A-Z]{3}', 'message' => 'Bad ref']]))->all();
        $this->assertSame('Other', $rules['ref']['group']);
        $this->assertArrayHasKey('uk_postcode', $rules);
    }

    public function testItKeysLibraryGroupsByStableCodesApartFromTheirLabels(): void
    {
        $library = $this->library([['code' => 'ref_no', 'label' => 'Reference', 'pattern' => '[A-Z]+', 'message' => 'x', 'example' => '']]);
        $this->assertSame(['postcode', 'Postcodes'], [$library->find('uk_postcode')['group_code'], $library->find('uk_postcode')['group']]);
        $this->assertSame('phone', $library->find('uk_phone')['group_code']);
        $this->assertSame('content', $library->find('no_links')['group_code']);
        $this->assertSame('', $library->find('saved:ref_no')['group_code']);
        foreach ((new BuiltInRules())->all() as $code => $rule) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $rule['group_code'], $code);
        }
        $extra = (new BuiltInRules(['ref' => ['label' => 'Ref', 'pattern' => '[A-Z]{3}', 'message' => 'Bad ref']]))->all();
        $this->assertSame('', $extra['ref']['group_code']);
    }

    public function testItResolvesBuiltInAndSavedReferences(): void
    {
        $library = $this->library([
            ['code' => 'ref_no', 'label' => 'Reference', 'pattern' => '[A-Z]{2}-[0-9]+', 'message' => 'Enter a ref.', 'example' => 'AB-1'],
            ['code' => 'evil', 'label' => 'Evil', 'pattern' => '(a+)+', 'message' => 'x', 'example' => ''],
        ]);
        $this->assertSame('UK postcode', $library->find('uk_postcode')['label']);
        $saved = $library->find('saved:ref_no');
        $this->assertSame('[A-Z]{2}-[0-9]+', $saved['pattern']);
        $this->assertTrue($saved['saved']);
        $this->assertNull($library->find('saved:evil'));
        $this->assertNull($library->find('ref_no'));
        $this->assertNull($library->find('saved:missing'));
        $refs = array_column($library->all(), 'ref');
        $this->assertContains('saved:ref_no', $refs);
        $this->assertNotContains('saved:evil', $refs);
    }

    public function testThePresetRuleValidatesThroughTheLibraryAndSkipsMissingReferences(): void
    {
        $preset = new Preset($this->library(), new PatternMatcher(new RepeatAnalyzer()));
        $this->assertTrue($preset->validate('SW1A 1AA', 'uk_postcode', []));
        $this->assertFalse($preset->validate('nope', 'uk_postcode', []));
        $this->assertFalse($preset->validate(['SW1A 1AA'], 'uk_postcode', []));
        $this->assertTrue($preset->validate('anything', 'saved:gone', []));
        $this->assertSame('Please enter a valid UK postcode, such as SW1A 1AA.', $preset->getDefaultMessage('uk_postcode'));
        $this->assertSame('Please match the requested format.', $preset->getDefaultMessage('saved:gone'));
    }

    public function testTheSchemaAcceptsKnownFormatsOnlyAndAllowsSeveralPerField(): void
    {
        $normalizer = $this->normalizer();
        $schema = $normalizer->normalize($this->schemaWith([
            ['rule' => 'preset', 'value' => 'uk_postcode', 'message' => ''],
            ['rule' => 'preset', 'value' => 'no_links', 'message' => 'No links please'],
            ['rule' => 'preset', 'value' => 'uk_postcode', 'message' => 'Dupe'],
        ]));
        $rules = $schema['pages'][0]['items'][0]['rules'];
        // A repeated format replaces the earlier one, as other repeated rules do.
        $this->assertSame(['no_links', 'uk_postcode'], array_column($rules, 'value'));
        $this->assertSame('Dupe', $rules[1]['message']);

        try {
            $normalizer->normalize($this->schemaWith([['rule' => 'preset', 'value' => 'saved:gone']]));
            $this->fail('Expected an unknown format to be rejected');
        } catch (InvalidSchemaException $e) {
            $this->assertStringContainsString('the format rule "saved:gone" does not exist', implode(' ', $e->getErrors()));
        }
    }

    public function testThePublicSchemaSendsFormatsToTheBrowserAsPlainPatterns(): void
    {
        $preset = new Preset($this->library(), new PatternMatcher(new RepeatAnalyzer()));
        $public = new PublicSchema(new RulePool(['preset' => $preset]), new Pool(['text' => new Text()]), $this->createStub(Config::class), new \Furan\FormBuilder\Model\Upload\ExtensionList());
        $field = $this->schemaWith([
            ['rule' => 'preset', 'value' => 'uk_postcode', 'message' => ''],
            ['rule' => 'preset', 'value' => 'saved:gone', 'message' => ''],
        ])['pages'][0]['items'][0] + ['id' => 'f_aaaa01', 'condition' => null];
        $built = $public->build(['version' => 1, 'pages' => [['id' => 'p_aaaa01', 'title' => 'P', 'condition' => null, 'items' => [$field]]]]);
        $rules = $built['pages'][0]['items'][0]['rules'];
        $this->assertCount(1, $rules);
        $this->assertSame('pattern', $rules[0]['rule']);
        $this->assertSame((new BuiltInRules())->all()['uk_postcode']['pattern'], $rules[0]['value']);
        $this->assertSame('Please enter a valid UK postcode, such as SW1A 1AA.', $rules[0]['message']);
    }

    private function schemaWith(array $rules): array
    {
        return ['pages' => [['title' => 'P', 'items' => [
            ['kind' => 'field', 'type' => 'text', 'name' => 'postcode', 'label' => 'Postcode', 'rules' => $rules],
        ]]]];
    }

    private function normalizer(): Normalizer
    {
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn([]);
        return new Normalizer(
            new Pool(['text' => new Text(), 'email' => new \Furan\FormBuilder\Model\FieldType\Email(), 'tel' => new \Furan\FormBuilder\Model\FieldType\Tel()]),
            new RulePool(['preset' => new Preset($this->library(), new PatternMatcher(new RepeatAnalyzer()))]),
            new PatternMatcher(new RepeatAnalyzer()),
            new \Furan\FormBuilder\Model\Schema\SettingValues(new RedirectValidator($manager)),
            new PersonalData(\Furan\FormBuilder\Test\Unit\Helper\FieldPools::builtIn()),
            new Sources(),
            \Furan\FormBuilder\Test\Unit\Helper\ConditionNormalizers::builtIn(),
            \Furan\FormBuilder\Test\Unit\Helper\FieldChecksFactory::create(),
        new \Furan\FormBuilder\Model\Validation\PresetFit()
);
    }

    public function testTheNormalizerRefusesALibraryFormatOutsideTheTypesGroups(): void
    {
        $field = static fn (string $type, string $ref): array => ['kind' => 'field', 'type' => $type, 'name' => $type . '_f',
            'label' => 'Answer', 'rules' => [['rule' => 'preset', 'value' => $ref]]];
        try {
            $this->normalizer()->normalize(['pages' => [['items' => [$field('email', 'uk_postcode')]]]]);
            $this->fail('Expected InvalidSchemaException');
        } catch (\Furan\FormBuilder\Api\Exception\InvalidSchemaException $e) {
            $this->assertSame(['Field "Answer": the format "UK postcode" does not apply to this field type.'], $e->getErrors());
        }
        $items = $this->normalizer()->normalize(['pages' => [['items' => [$field('tel', 'uk_phone'), $field('text', 'uk_postcode')]]]])['pages'][0]['items'];
        $this->assertSame(['uk_phone', 'uk_postcode'], [$items[0]['rules'][0]['value'], $items[1]['rules'][0]['value']]);
    }

    public function testAFormatMessageReplacesTheGenericTypeMessageInPhpAndTheBrowser(): void
    {
        $preset = new Preset($this->library(), new PatternMatcher(new RepeatAnalyzer()));
        $rules = new RulePool(['preset' => $preset]);
        $field = [
            'id' => 'f_aaaa01', 'kind' => 'field', 'type' => 'tel', 'name' => 'phone', 'label' => 'Phone', 'required' => false,
            'rules' => [['rule' => 'preset', 'value' => 'uk_phone', 'message' => '']], 'options' => [], 'settings' => [], 'condition' => null,
        ];
        $schema = ['version' => 1, 'pages' => [['id' => 'p_aaaa01', 'title' => 'P', 'condition' => null, 'items' => [$field]]]];
        $expected = 'Please enter a UK phone number, such as 020 7946 0958 or +44 20 7946 0958.';

        $validator = new \Furan\FormBuilder\Model\Submission\Validator(
            new Pool(['tel' => new \Furan\FormBuilder\Model\FieldType\Tel()]),
            $rules,
            new \Furan\FormBuilder\Model\Condition\Evaluator(\Furan\FormBuilder\Test\Unit\Helper\OperatorPools::builtIn(), \Furan\FormBuilder\Test\Unit\Helper\FieldPools::builtIn(), \Furan\FormBuilder\Test\Unit\Helper\ActionPools::builtIn()),
            $this->createStub(\Psr\Log\LoggerInterface::class),
            new \Furan\FormBuilder\Model\Condition\Today($this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class))
        );
        $this->assertSame($expected, $validator->validate($schema, ['phone' => '12'], [])->getErrors()['phone']);
        $this->assertSame($expected, $validator->validate($schema, ['phone' => '123456789'], [])->getErrors()['phone']);

        $public = new PublicSchema($rules, new Pool(['tel' => new \Furan\FormBuilder\Model\FieldType\Tel()]), $this->createStub(Config::class), new \Furan\FormBuilder\Model\Upload\ExtensionList());
        $built = $public->build($schema)['pages'][0]['items'][0];
        $this->assertSame(['pattern' => (new BuiltInRules())->all()['uk_phone']['pattern'], 'message' => $expected], $built['format']);
        $this->assertSame('Please enter a valid phone number.', $built['type_message']);

        // An unrelated format keeps the type's own message when the answer does not break that format.
        $email = ['type' => 'email', 'name' => 'email', 'rules' => [['rule' => 'preset', 'value' => 'no_links', 'message' => '']]] + $field;
        $emailSchema = ['version' => 1, 'pages' => [['id' => 'p_aaaa01', 'title' => 'P', 'condition' => null, 'items' => [$email]]]];
        $emailValidator = new \Furan\FormBuilder\Model\Submission\Validator(
            new Pool(['email' => new \Furan\FormBuilder\Model\FieldType\Email()]),
            $rules,
            new \Furan\FormBuilder\Model\Condition\Evaluator(\Furan\FormBuilder\Test\Unit\Helper\OperatorPools::builtIn(), \Furan\FormBuilder\Test\Unit\Helper\FieldPools::builtIn(), \Furan\FormBuilder\Test\Unit\Helper\ActionPools::builtIn()),
            $this->createStub(\Psr\Log\LoggerInterface::class),
            new \Furan\FormBuilder\Model\Condition\Today($this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class))
        );
        $this->assertSame('Please enter a valid email address.', $emailValidator->validate($emailSchema, ['email' => 'abc'], [])->getErrors()['email']);

        $schema['pages'][0]['items'][0]['rules'][0]['value'] = 'saved:gone';
        $this->assertSame('Please enter a valid phone number.', $validator->validate($schema, ['phone' => '12'], [])->getErrors()['phone']);
    }
}
