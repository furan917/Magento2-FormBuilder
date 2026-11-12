<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Schema;

use Furan\FormBuilder\Model\FieldType\Date;
use Furan\FormBuilder\Model\FieldType\Hidden;
use Furan\FormBuilder\Model\FieldType\Number;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Select;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\Prefill\Sources;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\Normalizer;
use Furan\FormBuilder\Model\Schema\PersonalData;
use Furan\FormBuilder\Model\Validation\PatternMatcher;
use Furan\FormBuilder\Model\Validation\RepeatAnalyzer;
use Furan\FormBuilder\Model\Validation\Rule\Alpha;
use Furan\FormBuilder\Model\Validation\Rule\Matches;
use Furan\FormBuilder\Model\Validation\Rule\Max;
use Furan\FormBuilder\Model\Validation\Rule\MaxDate;
use Furan\FormBuilder\Model\Validation\Rule\MaxLength;
use Furan\FormBuilder\Model\Validation\Rule\Min;
use Furan\FormBuilder\Model\Validation\Rule\MinLength;
use Furan\FormBuilder\Model\Validation\Rule\Pattern;
use Furan\FormBuilder\Model\Validation\RulePool;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class NormalizerLogicTest extends TestCase
{
    private Normalizer $normalizer;

    protected function setUp(): void
    {
        $matcher = new PatternMatcher(new RepeatAnalyzer());
        $this->normalizer = new Normalizer(
            new Pool([
                'text' => new Text(),
                'number' => new Number(),
                'date' => new Date(),
                'hidden' => new Hidden(),
                'select' => new Select(),
            ]),
            new RulePool([
                'min_length' => new MinLength(),
                'max_length' => new MaxLength(),
                'pattern' => new Pattern($matcher),
                'alpha' => new Alpha(),
                'matches' => new Matches(),
                'min' => new Min(),
                'max' => new Max(),
                'max_date' => new MaxDate(),
            ]),
            $matcher,
            new \Furan\FormBuilder\Model\Schema\SettingValues($this->redirectValidator()),
            new PersonalData(\Furan\FormBuilder\Test\Unit\Helper\FieldPools::builtIn()),
            new Sources(),
            \Furan\FormBuilder\Test\Unit\Helper\ConditionNormalizers::builtIn(),
            \Furan\FormBuilder\Test\Unit\Helper\FieldChecksFactory::create(),
        new \Furan\FormBuilder\Model\Validation\PresetFit()
);
    }

    private function redirectValidator(): RedirectValidator
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn([$store]);
        return new RedirectValidator($manager);
    }

    private function field(array $over = []): array
    {
        return $over + ['kind' => 'field', 'type' => 'text', 'name' => 'first_name', 'label' => 'First name'];
    }

    private function wrap(array ...$items): array
    {
        return ['pages' => [['title' => 'P', 'items' => $items]]];
    }

    private function errorsOf(array $raw): array
    {
        try {
            $this->normalizer->normalize($raw);
        } catch (InvalidSchemaException $e) {
            return $e->getErrors();
        }
        $this->fail('Expected InvalidSchemaException');
    }

    private function firstItem(array $raw): array
    {
        return $this->normalizer->normalize($raw)['pages'][0]['items'][0];
    }

    public function testItKeepsOnlyRulesAllowedForTheFieldType(): void
    {
        $item = $this->firstItem($this->wrap($this->field(['rules' => [['rule' => 'min_length', 'value' => 2]]])));
        $this->assertSame([['rule' => 'min_length', 'value' => 2, 'message' => '']], $item['rules']);

        $this->assertNotEmpty($this->errorsOf($this->wrap($this->field(['rules' => [['rule' => 'min', 'value' => 2]]]))));
        $this->assertNotEmpty($this->errorsOf($this->wrap($this->field(['rules' => [['rule' => 'nope']]]))));
        $this->assertNotEmpty($this->errorsOf($this->wrap($this->field(['rules' => array_fill(0, 21, ['rule' => 'alpha'])]))));

        $item = $this->firstItem($this->wrap($this->field(['rules' => [
            ['rule' => 'min_length', 'value' => 2, 'message' => 'a'],
            ['rule' => 'alpha'],
            ['rule' => 'min_length', 'value' => 5, 'message' => str_repeat('m', 300)],
        ]])));
        $this->assertSame(['alpha', 'min_length'], array_column($item['rules'], 'rule'));
        $this->assertSame(5, $item['rules'][1]['value']);
        $this->assertSame(255, mb_strlen($item['rules'][1]['message']));
    }

    public function testItCoercesRuleValuesByValueType(): void
    {
        $item = $this->firstItem($this->wrap($this->field(['rules' => [
            ['rule' => 'min_length', 'value' => '3'],
            ['rule' => 'alpha', 'value' => 'ignored'],
        ]])));
        $this->assertSame(3, $item['rules'][0]['value']);
        $this->assertNull($item['rules'][1]['value']);

        $item = $this->firstItem($this->wrap($this->field(['type' => 'number', 'rules' => [
            ['rule' => 'min', 'value' => '1.5'],
            ['rule' => 'max', 'value' => 10],
        ]])));
        $this->assertSame(1.5, $item['rules'][0]['value']);
        $this->assertSame(10.0, $item['rules'][1]['value']);

        $item = $this->firstItem($this->wrap($this->field(['type' => 'date', 'rules' => [
            ['rule' => 'max_date', 'value' => '2030-01-31'],
        ]])));
        $this->assertSame('2030-01-31', $item['rules'][0]['value']);

        $this->assertNotEmpty($this->errorsOf($this->wrap($this->field(['type' => 'date', 'rules' => [
            ['rule' => 'max_date', 'value' => '31/01/2030'],
        ]]))));
        $this->assertNotEmpty($this->errorsOf($this->wrap($this->field(['rules' => [
            ['rule' => 'min_length', 'value' => 'abc'],
        ]]))));
    }

    public function testItRejectsUnsafeRegexPatterns(): void
    {
        foreach (['(a+)+', '([', str_repeat('a', 257)] as $pattern) {
            $errors = $this->errorsOf($this->wrap($this->field(['rules' => [['rule' => 'pattern', 'value' => $pattern]]])));
            $this->assertContains('Field "First name": the pattern is invalid or too complex.', $errors);
        }
        $item = $this->firstItem($this->wrap($this->field(['rules' => [['rule' => 'pattern', 'value' => '[A-Z]{3}']]])));
        $this->assertSame('[A-Z]{3}', $item['rules'][0]['value']);
    }

    public function testItRejectsMatchesRulesPointingAtLaterOrUnknownFields(): void
    {
        $ok = $this->normalizer->normalize($this->wrap(
            $this->field(['name' => 'email_a']),
            $this->field(['name' => 'email_b', 'rules' => [['rule' => 'matches', 'value' => 'email_a']]])
        ));
        $this->assertSame('email_a', $ok['pages'][0]['items'][1]['rules'][0]['value']);

        $this->assertNotEmpty($this->errorsOf($this->wrap(
            $this->field(['name' => 'email_b', 'rules' => [['rule' => 'matches', 'value' => 'email_a']]]),
            $this->field(['name' => 'email_a'])
        )));
        $this->assertNotEmpty($this->errorsOf($this->wrap(
            $this->field(['name' => 'email_b', 'rules' => [['rule' => 'matches', 'value' => 'email_b']]])
        )));
        $this->assertNotEmpty($this->errorsOf($this->wrap(
            $this->field(['name' => 'email_b', 'rules' => [['rule' => 'matches', 'value' => 'ghost']]])
        )));
    }

    public function testItRejectsConditionsThatReferenceLaterFields(): void
    {
        $cond = ['rules' => [['field' => 'later', 'operator' => 'equals', 'value' => 'x']]];
        $this->assertNotEmpty($this->errorsOf($this->wrap(
            $this->field(['name' => 'first', 'condition' => $cond]),
            $this->field(['name' => 'later'])
        )));
        $this->assertNotEmpty($this->errorsOf($this->wrap(
            $this->field(['name' => 'first', 'condition' => ['rules' => [['field' => 'first', 'operator' => 'equals']]]])
        )));
        $this->assertNotEmpty($this->errorsOf($this->wrap(
            ['kind' => 'content', 'type' => 'divider', 'condition' => $cond],
            $this->field(['name' => 'later'])
        )));
        $this->assertNotEmpty($this->errorsOf($this->wrap(
            ['kind' => 'section', 'title' => 'S', 'condition' => $cond, 'items' => [$this->field(['name' => 'later'])]]
        )));
        $raw = ['pages' => [
            ['title' => 'A', 'condition' => $cond, 'items' => []],
            ['title' => 'B', 'items' => [$this->field(['name' => 'later'])]],
        ]];
        $this->assertNotEmpty($this->errorsOf($raw));

        $ok = $this->normalizer->normalize($this->wrap(
            $this->field(['name' => 'later']),
            ['kind' => 'section', 'title' => 'S', 'condition' => $cond, 'items' => []]
        ));
        $this->assertNotNull($ok['pages'][0]['items'][1]['condition']);
    }

    public function testItNormalisesConditionActionMatchAndOperators(): void
    {
        $item = $this->normalizer->normalize($this->wrap(
            $this->field(['name' => 'a']),
            $this->field(['name' => 'b', 'condition' => [
                'actions' => 'weird', 'match' => 'weird', 'extra' => 1,
                'rules' => [['field' => 'a', 'operator' => 'contains', 'value' => str_repeat('v', 300), 'x' => 1]],
            ]]),
            $this->field(['name' => 'c', 'condition' => [
                'actions' => [['action' => 'hide']], 'match' => 'any',
                'rules' => [['field' => 'a', 'operator' => 'is_empty']],
            ]])
        ))['pages'][0]['items'];

        $this->assertSame(['actions', 'match', 'rules'], array_keys($item[1]['condition']));
        $this->assertSame([['action' => 'show']], $item[1]['condition']['actions']);
        $this->assertSame('all', $item[1]['condition']['match']);
        $this->assertSame(['field', 'operator', 'value'], array_keys($item[1]['condition']['rules'][0]));
        $this->assertSame(300, mb_strlen($item[1]['condition']['rules'][0]['value']));
        $this->assertSame([['action' => 'hide']], $item[2]['condition']['actions']);
        $this->assertSame('any', $item[2]['condition']['match']);
        $this->assertSame('', $item[2]['condition']['rules'][0]['value']);

        $this->assertNotEmpty($this->errorsOf($this->wrap(
            $this->field(['name' => 'a']),
            $this->field(['name' => 'b', 'condition' => ['rules' => [['field' => 'a', 'operator' => 'regex', 'value' => 'x']]]])
        )));
        $many = array_fill(0, 26, ['field' => 'a', 'operator' => 'equals', 'value' => 'x']);
        $this->assertNotEmpty($this->errorsOf($this->wrap(
            $this->field(['name' => 'a']),
            $this->field(['name' => 'b', 'condition' => ['rules' => $many]])
        )));
    }

    public function testItDropsAConditionWithNoRules(): void
    {
        foreach ([['rules' => []], ['action' => 'hide'], 'junk', []] as $condition) {
            $this->assertNull($this->firstItem($this->wrap($this->field(['condition' => $condition])))['condition']);
        }
    }

    public function testItSlugsEmptyOptionValuesAndRejectsDuplicates(): void
    {
        $item = $this->firstItem($this->wrap($this->field(['type' => 'select', 'options' => [
            ['label' => 'Red Apple', 'value' => ''],
            ['label' => 'Green', 'value' => 'g'],
            ['label' => 'Ünï', 'value' => ''],
            ['label' => '日本'],
            ['label' => 'Plain', 'value' => 'x', 'extra' => 1],
        ]])));
        $this->assertSame(
            [
                ['label' => 'Red Apple', 'value' => 'red_apple'],
                ['label' => 'Green', 'value' => 'g'],
                ['label' => 'Ünï', 'value' => 'n'],
                ['label' => '日本', 'value' => 'option_4'],
                ['label' => 'Plain', 'value' => 'x'],
            ],
            $item['options']
        );

        $this->assertNotEmpty($this->errorsOf($this->wrap($this->field(['type' => 'select', 'options' => [
            ['label' => 'A', 'value' => 'same'],
            ['label' => 'B', 'value' => 'same'],
        ]]))));
        $this->assertNotEmpty($this->errorsOf($this->wrap($this->field(['type' => 'select', 'options' => [
            ['label' => 'Red', 'value' => ''],
            ['label' => 'red', 'value' => ''],
        ]]))));
        $this->assertNotEmpty($this->errorsOf($this->wrap($this->field(['type' => 'select', 'options' => [
            ['label' => '', 'value' => 'x'],
        ]]))));
        $this->assertNotEmpty($this->errorsOf($this->wrap($this->field(['type' => 'select', 'options' => array_map(
            static fn (int $i): array => ['label' => 'L' . $i, 'value' => 'v' . $i],
            range(1, 501)
        )]))));
    }

    public function testItRequiresOptionsForChoiceFields(): void
    {
        $errors = $this->errorsOf($this->wrap($this->field(['type' => 'select', 'options' => []])));
        $this->assertContains('Field "First name" needs at least one option.', $errors);

        $item = $this->firstItem($this->wrap($this->field(['options' => [['label' => 'A', 'value' => 'a']]])));
        $this->assertSame([], $item['options']);
    }

    public function testItBlanksHiddenFieldQueryParamsThatAreNotAlphanumericDashOrUnderscore(): void
    {
        $values = ['utm_source-1' => 'utm_source-1', 'a b' => '', 'a<b>' => '', '' => '', str_repeat('a', 65) => ''];
        foreach ($values as $input => $expected) {
            $item = $this->firstItem($this->wrap($this->field([
                'type' => 'hidden', 'settings' => ['source' => 'query', 'query_param' => (string)$input],
            ])));
            $this->assertSame($expected, $item['settings']['query_param']);
        }
    }
}
