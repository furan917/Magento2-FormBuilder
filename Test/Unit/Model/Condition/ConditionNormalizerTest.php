<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Condition;

use Furan\FormBuilder\Model\Condition\Action\Hide;
use Furan\FormBuilder\Model\Condition\Action\RequireField;
use Furan\FormBuilder\Model\Condition\Action\SetValue;
use Furan\FormBuilder\Model\Condition\Action\Show;
use Furan\FormBuilder\Model\Condition\ConditionNormalizer;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Test\Unit\Helper\SmsConsentType;
use Furan\FormBuilder\Test\Unit\Helper\ConditionNormalizers;
use PHPUnit\Framework\TestCase;

class ConditionNormalizerTest extends TestCase
{
    private const SOURCES = ['a' => 'text', 'b' => 'text', 'when' => 'date', 'stamp' => 'datetime', 'qty' => 'number'];

    private function normalize(
        array|null $condition,
        array &$errors,
        array $stored = [],
        ?ConditionNormalizer $normalizer = null
    ): ?array {
        return ($normalizer ?? ConditionNormalizers::builtIn())
            ->normalize($condition, self::SOURCES, $stored, 'Target', $errors);
    }

    private function rule(string $field = 'a', string $operator = 'equals', string $value = 'x'): array
    {
        return ['field' => $field, 'operator' => $operator, 'value' => $value];
    }

    private function group(array $rules, string $match = 'all'): array
    {
        return ['match' => $match, 'rules' => $rules];
    }

    private function condition(array $rules): array
    {
        return ['match' => 'all', 'rules' => $rules];
    }

    /** @return array[] */
    private function leaves(int $count): array
    {
        return array_fill(0, $count, $this->rule());
    }

    public function testItAcceptsANestedGroupInsideACondition(): void
    {
        $errors = [];
        $result = $this->normalize($this->condition([
            $this->rule('a'),
            ['match' => 'any', 'rules' => [$this->rule('b', 'not_empty', ''), $this->rule('a', 'contains', 'y')], 'junk' => 1],
        ]), $errors);

        $this->assertSame([], $errors);
        $this->assertSame([
            'actions' => [['action' => 'show']],
            'match' => 'all',
            'rules' => [
                ['field' => 'a', 'operator' => 'equals', 'value' => 'x'],
                ['match' => 'any', 'rules' => [
                    ['field' => 'b', 'operator' => 'not_empty', 'value' => ''],
                    ['field' => 'a', 'operator' => 'contains', 'value' => 'y'],
                ]],
            ],
        ], $result);
    }

    public function testItRejectsGroupsNestedDeeperThanThreeLevels(): void
    {
        $level3 = $this->group([$this->rule()]);
        $level2 = $this->group([$level3]);
        $errors = [];
        $this->normalize($this->condition([$level2]), $errors);
        $this->assertSame([], $errors, 'root + two nested groups is three levels');

        $errors = [];
        $this->normalize($this->condition([$this->group([$this->group([$this->group([$this->rule()])])])]), $errors);
        $this->assertSame(['"Target": condition groups can be nested at most 3 levels deep.'], $errors);
    }

    public function testItRejectsAConditionWithMoreThanTwentyFiveRulesInTotal(): void
    {
        $errors = [];
        $this->normalize($this->condition([$this->group($this->leaves(24))]), $errors);
        $this->assertSame([], $errors, 'a group and 24 rules make exactly 25');

        $errors = [];
        $this->normalize($this->condition([$this->group($this->leaves(25))]), $errors);
        $this->assertSame(['"Target" can have at most 25 condition rules.'], $errors);

        $errors = [];
        $groups = [$this->group($this->leaves(9)), $this->group($this->leaves(9)), $this->group($this->leaves(9))];
        $this->normalize($this->condition($groups), $errors);
        $this->assertSame(['"Target" can have at most 25 condition rules.'], $errors);
    }

    public function testItAcceptsARuleComparingWithAnEarlierField(): void
    {
        $errors = [];
        $result = $this->normalize($this->condition([
            ['field' => 'a', 'operator' => 'equals', 'value_field' => 'b', 'value' => 'ignored'],
        ]), $errors);

        $this->assertSame([], $errors);
        $this->assertSame([['field' => 'a', 'operator' => 'equals', 'value_field' => 'b']], $result['rules']);
    }

    public function testItRejectsARuleComparingWithALaterField(): void
    {
        $errors = [];
        $this->normalize($this->condition([
            ['field' => 'a', 'operator' => 'equals', 'value_field' => 'later'],
        ]), $errors);

        $this->assertSame(['"Target": a condition can only use fields that appear earlier in the form.'], $errors);
    }

    public function testItAcceptsARelativeDateRuleOnADateField(): void
    {
        $errors = [];
        $result = $this->normalize($this->condition([
            ['field' => 'when', 'operator' => 'greater_than', 'value_date' => ['days' => -30, 'x' => 1]],
            ['field' => 'stamp', 'operator' => 'less_than', 'value_date' => ['days' => '7']],
        ]), $errors);

        $this->assertSame([], $errors);
        $this->assertSame([
            ['field' => 'when', 'operator' => 'greater_than', 'value_date' => ['days' => -30]],
            ['field' => 'stamp', 'operator' => 'less_than', 'value_date' => ['days' => 7]],
        ], $result['rules']);
    }

    public function testItRejectsARelativeDateOutsideThePermittedRange(): void
    {
        foreach ([3651, -3651, 1.5, true, 'soon', null, []] as $days) {
            $errors = [];
            $this->normalize($this->condition([
                ['field' => 'when', 'operator' => 'greater_than', 'value_date' => ['days' => $days]],
            ]), $errors);
            $this->assertSame(['"Target": a relative date must be a whole number of days between -3650 and 3650.'], $errors);
        }
        $errors = [];
        $this->normalize($this->condition([
            ['field' => 'when', 'operator' => 'greater_than', 'value_date' => ['days' => 3650]],
            ['field' => 'when', 'operator' => 'greater_than', 'value_date' => ['days' => '-3650']],
        ]), $errors);
        $this->assertSame([], $errors);
    }

    public function testItKeepsAFlatConditionUnchanged(): void
    {
        $errors = [];
        $result = $this->normalize([
            'actions' => [['action' => 'hide']],
            'match' => 'any',
            'rules' => [$this->rule('a', 'equals', 'x'), $this->rule('qty', 'greater_than', '5')],
        ], $errors);

        $this->assertSame([], $errors);
        $this->assertSame([
            'actions' => [['action' => 'hide']],
            'match' => 'any',
            'rules' => [
                ['field' => 'a', 'operator' => 'equals', 'value' => 'x'],
                ['field' => 'qty', 'operator' => 'greater_than', 'value' => '5'],
            ],
        ], $result);
    }

    public function testItRejectsARelativeDateRuleOnAFieldTypeWithoutRelativeDateSupport(): void
    {
        $message = ['"Target": a rule can use only one of a value, another field or a relative date, and only with an operator that compares a value.'];
        $errors = [];
        $this->normalize($this->condition([
            ['field' => 'a', 'operator' => 'equals', 'value_date' => ['days' => 1]],
        ]), $errors);
        $this->assertSame(['"Target": a relative date needs a date field.'], $errors);

        $errors = [];
        $this->normalize($this->condition([
            ['field' => 'when', 'operator' => 'is_empty', 'value_date' => ['days' => 1]],
        ]), $errors);
        $this->assertSame($message, $errors);

        $errors = [];
        $this->normalize($this->condition([
            ['field' => 'when', 'operator' => 'equals', 'value_field' => 'b', 'value_date' => ['days' => 1]],
        ]), $errors);
        $this->assertSame($message, $errors);
    }

    public function testItKeepsADisabledOperatorRuleInsideANestedGroupOfTheSavedItem(): void
    {
        $disabled = $this->rule('a', 'sounds_like', 'x');
        $saved = $this->condition([$this->group([$this->group([$disabled])])]);
        $normalizer = ConditionNormalizers::builtIn();

        $errors = [];
        $result = $this->normalize($saved, $errors, $normalizer->flatten($saved), $normalizer);
        $this->assertSame([], $errors);
        $this->assertSame(['actions' => [['action' => 'show']]] + array_diff_key($saved, ['action' => 1]), $result);

        $errors = [];
        $changed = $this->condition([$this->group([$this->group([$this->rule('a', 'sounds_like', 'changed')])])]);
        $this->normalize($changed, $errors, $normalizer->flatten($saved), $normalizer);
        $this->assertSame(['"Target": unknown condition operator.'], $errors);
    }

    public function testItFlattensGroupsOfASavedConditionIntoTheirRules(): void
    {
        $saved = $this->condition([$this->rule('a'), $this->group([$this->rule('b'), $this->group([$this->rule('qty')])])]);

        $this->assertSame(
            [$this->rule('a'), $this->rule('b'), $this->rule('qty')],
            ConditionNormalizers::builtIn()->flatten($saved)
        );
        $this->assertSame([], ConditionNormalizers::builtIn()->flatten(null));
    }

    public function testItKeepsNewlinesInARuleValueUpToOneThousandCharacters(): void
    {
        $value = "line one\nline two";
        $errors = [];
        $result = $this->normalize($this->condition([$this->rule('a', 'equals', $value)]), $errors);
        $this->assertSame([], $errors);
        $this->assertSame($value, $result['rules'][0]['value']);

        $errors = [];
        $result = $this->normalize($this->condition([$this->rule('a', 'equals', str_repeat('é', 1001))]), $errors);
        $this->assertSame(str_repeat('é', 1000), $result['rules'][0]['value']);
    }

    public function testItDecidesConsentActionFitInOnePlace(): void
    {
        $consent = new SmsConsentType();
        $text = new Text();

        foreach ([new RequireField(), new SetValue()] as $action) {
            $this->assertFalse(ConditionNormalizer::actionFits($action, $consent), $action->getCode());
            $this->assertTrue(ConditionNormalizer::actionFits($action, $text), $action->getCode());
        }
        foreach ([new Show(), new Hide()] as $action) {
            $this->assertTrue(ConditionNormalizer::actionFits($action, $consent), $action->getCode());
        }
    }
}
