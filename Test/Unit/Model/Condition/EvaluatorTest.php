<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Condition;

use Furan\FormBuilder\Model\Condition\Evaluator;
use Furan\FormBuilder\Test\Unit\Helper\ArrayAwareOperator;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Test\Unit\Helper\OperatorPools;
use PHPUnit\Framework\TestCase;

class EvaluatorTest extends TestCase
{
    private Evaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new Evaluator(OperatorPools::builtIn(), FieldPools::builtIn(), \Furan\FormBuilder\Test\Unit\Helper\ActionPools::builtIn());
    }

    private static function fixture(string $name): array
    {
        return json_decode(
            (string)file_get_contents(__DIR__ . '/../../../fixtures/' . $name . '.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    private function condition(string $action, string $match, array ...$rules): array
    {
        return ['actions' => [['action' => $action]], 'match' => $match, 'rules' => $rules];
    }

    private function rule(string $field, string $operator, string $value = ''): array
    {
        return ['field' => $field, 'operator' => $operator, 'value' => $value];
    }

    public function testItTreatsANullConditionAsVisible(): void
    {
        $this->assertTrue($this->evaluator->evaluate(null, []));
    }

    public function testItPassesAllSharedConditionFixtures(): void
    {
        $cases = self::fixture('conditions');
        $this->assertGreaterThanOrEqual(70, count($cases));
        foreach ($cases as $case) {
            $this->assertSame(
                $case['expected'],
                $this->evaluator->evaluate($case['condition'], $case['values'], $case['fields'] ?? [], $case['today'] ?? null),
                $case['name']
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function objectCases(string $needle): array
    {
        $cases = array_values(array_filter(
            self::fixture('conditions'),
            static fn (array $case): bool => str_contains($case['name'], $needle)
        ));
        self::assertNotEmpty($cases);
        return $cases;
    }

    public function testItTreatsAnObjectAnswerAsEmptyInPhp(): void
    {
        foreach (self::objectCases('object answer') as $case) {
            $this->assertSame(
                $case['expected'],
                $this->evaluator->evaluate($case['condition'], $case['values'], $case['fields'] ?? [], $case['today'] ?? null),
                $case['name']
            );
        }
    }

    public function testItTreatsAnObjectComparedFieldAsEmptyOnBothSides(): void
    {
        foreach (self::objectCases('object compared field') as $case) {
            $this->assertSame(
                $case['expected'],
                $this->evaluator->evaluate($case['condition'], $case['values']),
                $case['name']
            );
        }
    }

    public function testItStillMatchesAListAnswerWhoseKeysHaveGaps(): void
    {
        $condition = $this->condition('show', 'all', $this->rule('a', 'equals', 'green'));
        $this->assertTrue($this->evaluator->evaluate($condition, ['a' => [0 => 'red', 2 => 'green']]));
        $other = $this->condition('show', 'all', ['field' => 'a', 'operator' => 'equals', 'value_field' => 'b']);
        $this->assertTrue($this->evaluator->evaluate($other, ['a' => 'green', 'b' => [1 => 'green']]));
    }

    public function testItCombinesRulesWithAllAndAny(): void
    {
        $values = ['a' => '1', 'b' => '2'];
        $all = $this->condition('show', 'all', $this->rule('a', 'equals', '1'), $this->rule('b', 'equals', '9'));
        $any = $this->condition('show', 'any', $this->rule('a', 'equals', '1'), $this->rule('b', 'equals', '9'));
        $this->assertFalse($this->evaluator->evaluate($all, $values));
        $this->assertTrue($this->evaluator->evaluate($any, $values));
        $this->assertTrue($this->evaluator->evaluate($this->condition('show', 'all'), $values));
    }

    public function testItInvertsTheResultForHideActions(): void
    {
        $hide = $this->condition('hide', 'all', $this->rule('a', 'equals', '1'));
        $this->assertFalse($this->evaluator->evaluate($hide, ['a' => '1']));
        $this->assertTrue($this->evaluator->evaluate($hide, ['a' => '2']));
    }

    public function testItMatchesArrayValuesWhenAnyElementMatches(): void
    {
        $values = ['a' => ['Red', 'blue']];
        $equals = $this->condition('show', 'all', $this->rule('a', 'equals', 'red'));
        $empty = $this->condition('show', 'all', $this->rule('a', 'is_empty'));
        $this->assertTrue($this->evaluator->evaluate($equals, $values));
        $this->assertFalse($this->evaluator->evaluate($empty, $values));
        $this->assertTrue($this->evaluator->evaluate($empty, ['a' => []]));
    }

    public function testItComputesVisibilityForEveryCaseInTheSharedVisibilityFixture(): void
    {
        $cases = self::fixture('visibility');
        $this->assertGreaterThanOrEqual(9, count($cases));
        foreach ($cases as $case) {
            $this->assertSame(
                $case['expected'],
                $this->evaluator->computeVisibility($case['schema'], $case['values'], $case['today'] ?? null),
                $case['name']
            );
        }
    }

    public function testItHidesItemsInsideAHiddenPageOrSection(): void
    {
        $field = static fn (string $id, string $name, ?array $c = null): array =>
            ['id' => $id, 'kind' => 'field', 'type' => 'text', 'name' => $name, 'condition' => $c];
        $hide = $this->condition('hide', 'all', $this->rule('a', 'equals', 'x'));
        $schema = ['pages' => [
            ['id' => 'p_1', 'condition' => null, 'items' => [
                $field('f_a', 'a'),
                ['id' => 's_1', 'kind' => 'section', 'condition' => $hide, 'items' => [$field('f_b', 'b')]],
            ]],
            ['id' => 'p_2', 'condition' => $hide, 'items' => [$field('f_c', 'c')]],
        ]];
        $result = $this->evaluator->computeVisibility($schema, ['a' => 'x']);
        $this->assertFalse($result['s_1']);
        $this->assertFalse($result['f_b']);
        $this->assertFalse($result['p_2']);
        $this->assertFalse($result['f_c']);
        $this->assertTrue($result['f_a']);
    }

    public function testItTreatsValuesOfHiddenFieldsAsEmptyForLaterConditions(): void
    {
        $schema = ['pages' => [['id' => 'p_1', 'condition' => null, 'items' => [
            ['id' => 'f_a', 'kind' => 'field', 'name' => 'a', 'condition' => null],
            ['id' => 'f_b', 'kind' => 'field', 'name' => 'b',
                'condition' => $this->condition('show', 'all', $this->rule('a', 'equals', 'x'))],
            ['id' => 'f_c', 'kind' => 'field', 'name' => 'c',
                'condition' => $this->condition('show', 'all', $this->rule('b', 'is_empty'))],
        ]]]];
        $result = $this->evaluator->computeVisibility($schema, ['a' => 'y', 'b' => 'secret']);
        $this->assertFalse($result['f_b']);
        $this->assertTrue($result['f_c']);
    }

    public function testItEvaluatesConditionsThroughTheOperatorPool(): void
    {
        $custom = new ArrayAwareOperator();
        $evaluator = new Evaluator(OperatorPools::builtIn([$custom->getCode() => $custom]), FieldPools::builtIn(), \Furan\FormBuilder\Test\Unit\Helper\ActionPools::builtIn());
        $this->assertTrue($evaluator->evaluate($this->condition('show', 'all', $this->rule('a', 'sounds_like', 'yes')), []));
        $this->assertFalse($evaluator->evaluate($this->condition('show', 'all', $this->rule('a', 'sounds_like', 'no')), []));
        $this->assertFalse($evaluator->evaluate($this->condition('show', 'all', $this->rule('a', 'equals', 'x')), ['a' => 'y']));
    }

    public function testItPassesTheRawValuesAndTheSourceFieldToACustomOperator(): void
    {
        $custom = new ArrayAwareOperator();
        $evaluator = new Evaluator(OperatorPools::builtIn([$custom->getCode() => $custom]), FieldPools::builtIn(), \Furan\FormBuilder\Test\Unit\Helper\ActionPools::builtIn());
        $field = ['id' => 'f_aaaa01', 'kind' => 'field', 'type' => 'checkboxes', 'name' => 'colours'];
        $schema = ['pages' => [['id' => 'p_aaaa01', 'condition' => null, 'items' => [
            $field,
            ['id' => 'c_aaaa01', 'kind' => 'content', 'condition' => $this->condition(
                'show',
                'any',
                $this->rule('colours', 'sounds_like', ' Yes '),
                $this->rule('ghost', 'sounds_like', 'no')
            )],
        ]]]];

        $evaluator->computeVisibility($schema, ['colours' => [' Red ', 'Blue']]);

        $this->assertSame([[' Red ', 'Blue'], ' Yes ', $field], $custom->calls[0]);
        $this->assertSame(['', 'no', []], $custom->calls[1]);
    }

    public function testItEvaluatesAnOperatorThatIsNotInThePoolAsFalse(): void
    {
        $this->assertFalse($this->evaluator->evaluate($this->condition('show', 'all', $this->rule('a', 'sounds_like', 'x')), ['a' => 'x']));
    }

    public function testItEvaluatesNestedAnyGroupsInsideAllGroups(): void
    {
        $group = ['match' => 'any', 'rules' => [$this->rule('b', 'equals', '1'), $this->rule('c', 'equals', '1')]];
        $condition = $this->condition('show', 'all', $this->rule('a', 'equals', '1'), $group);
        $this->assertTrue($this->evaluator->evaluate($condition, ['a' => '1', 'c' => '1']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['a' => '1']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['a' => '2', 'b' => '1']));
        $hide = $this->condition('hide', 'all', $this->rule('a', 'equals', '1'), $group);
        $this->assertFalse($this->evaluator->evaluate($hide, ['a' => '1', 'c' => '1']));
    }

    public function testItComparesAFieldWithAnotherFieldsAnswer(): void
    {
        $same = $this->condition('show', 'all', ['field' => 'email', 'operator' => 'equals', 'value_field' => 'email2']);
        $this->assertTrue($this->evaluator->evaluate($same, ['email' => ' A@x.com ', 'email2' => 'a@X.com']));
        $this->assertFalse($this->evaluator->evaluate($same, ['email' => 'a@x.com', 'email2' => 'b@x.com']));
        $this->assertFalse($this->evaluator->evaluate($same, ['email' => 'a@x.com']));
        $greater = $this->condition('show', 'all', ['field' => 'a', 'operator' => 'greater_than', 'value_field' => 'b']);
        $this->assertTrue($this->evaluator->evaluate($greater, ['a' => '10', 'b' => '9']));
        $this->assertFalse($this->evaluator->evaluate($greater, ['a' => '9', 'b' => '10']));
    }

    public function testItTreatsAHiddenComparisonFieldAsEmpty(): void
    {
        $schema = ['pages' => [['id' => 'p_1', 'condition' => null, 'items' => [
            ['id' => 'f_a', 'kind' => 'field', 'name' => 'a', 'condition' => null],
            ['id' => 'f_b', 'kind' => 'field', 'name' => 'b',
                'condition' => $this->condition('show', 'all', $this->rule('a', 'equals', 'x'))],
            ['id' => 'f_c', 'kind' => 'field', 'name' => 'c', 'condition' => $this->condition(
                'show',
                'all',
                ['field' => 'a', 'operator' => 'equals', 'value_field' => 'b']
            )],
        ]]]];
        $hidden = $this->evaluator->computeVisibility($schema, ['a' => 'y', 'b' => 'y']);
        $this->assertFalse($hidden['f_b']);
        $this->assertFalse($hidden['f_c']);
        $shown = $this->evaluator->computeVisibility($schema, ['a' => 'x', 'b' => 'x']);
        $this->assertTrue($shown['f_c']);
    }

    public function testItResolvesARelativeDateAgainstTodayInTheStoreTimezone(): void
    {
        $fields = ['d' => ['type' => 'date']];
        $after = static fn (string $operator, int $days): array => [
            'match' => 'all',
            'rules' => [['field' => 'd', 'operator' => $operator, 'value_date' => ['days' => $days]]],
        ];
        $today = '2026-11-07';
        $this->assertTrue($this->evaluator->evaluate($after('greater_than', 3), ['d' => '2026-11-11'], $fields, $today));
        $this->assertFalse($this->evaluator->evaluate($after('greater_than', 3), ['d' => '2026-11-10'], $fields, $today));
        $this->assertTrue($this->evaluator->evaluate($after('equals', -7), ['d' => '2026-10-31'], $fields, $today));
        $this->assertTrue($this->evaluator->evaluate($after('less_or_equal', 0), ['d' => '2026-11-07'], $fields, $today));
        $this->assertTrue($this->evaluator->evaluate($after('equals', 1), ['d' => '2027-03-01'], $fields, '2027-02-28'));
    }

    public function testItNeverMatchesARelativeDateWithoutAUsableTodayOrSource(): void
    {
        $rule = static fn (int $days): array => ['match' => 'all', 'rules' => [
            ['field' => 'd', 'operator' => 'equals', 'value_date' => ['days' => $days]],
        ]];
        $values = ['d' => '2026-11-07'];
        $date = ['d' => ['type' => 'date']];
        $this->assertTrue($this->evaluator->evaluate($rule(0), $values, $date, '2026-11-07'));
        $this->assertFalse($this->evaluator->evaluate($rule(0), $values, $date));
        foreach (['', '2026-02-31', '2026-13-01', '2026-11-7', "2026-11-07\n", 'today', '2026-11-07T10:00'] as $bad) {
            $this->assertFalse($this->evaluator->evaluate($rule(0), $values, $date, $bad), $bad);
        }
        $this->assertFalse($this->evaluator->evaluate($rule(0), $values, ['d' => ['type' => 'text']], '2026-11-07'));
        $this->assertFalse($this->evaluator->evaluate($rule(0), $values, ['d' => ['type' => 'ghost']], '2026-11-07'));
        $this->assertFalse($this->evaluator->evaluate($rule(0), $values, [], '2026-11-07'));
        $this->assertFalse($this->evaluator->evaluate($rule(99999), $values, $date, '2026-11-07'));
        $this->assertFalse($this->evaluator->evaluate($rule(3651), $values, $date, '2026-11-07'));
        $string = ['match' => 'all', 'rules' => [
            ['field' => 'd', 'operator' => 'equals', 'value_date' => ['days' => '0']],
        ]];
        $this->assertFalse($this->evaluator->evaluate($string, $values, $date, '2026-11-07'));
    }

    public function testItMatchesIsOneOfAgainstAListOfValues(): void
    {
        $oneOf = static fn (string $operator, string $list): array => ['match' => 'all', 'rules' => [
            ['field' => 'a', 'operator' => $operator, 'value' => $list],
        ]];
        $list = "Red\n  green \r\n\n\nBlue, Pink";
        $this->assertTrue($this->evaluator->evaluate($oneOf('is_one_of', $list), ['a' => 'GREEN']));
        $this->assertTrue($this->evaluator->evaluate($oneOf('is_one_of', $list), ['a' => ' blue, pink ']));
        $this->assertFalse($this->evaluator->evaluate($oneOf('is_one_of', $list), ['a' => 'blue']));
        $this->assertFalse($this->evaluator->evaluate($oneOf('is_one_of', $list), ['a' => '']));
        $this->assertFalse($this->evaluator->evaluate($oneOf('is_one_of', "\n \n"), ['a' => '']));
        $this->assertFalse($this->evaluator->evaluate($oneOf('is_not_one_of', $list), ['a' => 'red']));
        $this->assertTrue($this->evaluator->evaluate($oneOf('is_not_one_of', $list), ['a' => 'yellow']));
        $this->assertTrue($this->evaluator->evaluate($oneOf('is_not_one_of', $list), ['a' => '']));
        $fromField = $this->condition('show', 'all', ['field' => 'a', 'operator' => 'is_one_of', 'value_field' => 'b']);
        $this->assertTrue($this->evaluator->evaluate($fromField, ['a' => 'x', 'b' => ['w', 'X']]));
        $this->assertFalse($this->evaluator->evaluate($fromField, ['a' => 'q', 'b' => ['w', 'X']]));
    }

    public function testItMatchesIsOneOfWhenAnySelectedOptionIsInTheList(): void
    {
        $oneOf = static fn (string $operator): array => ['match' => 'all', 'rules' => [
            ['field' => 'a', 'operator' => $operator, 'value' => "red\ngreen"],
        ]];
        $this->assertTrue($this->evaluator->evaluate($oneOf('is_one_of'), ['a' => ['Blue', 'GREEN']]));
        $this->assertFalse($this->evaluator->evaluate($oneOf('is_one_of'), ['a' => ['Blue', 'pink']]));
        $this->assertFalse($this->evaluator->evaluate($oneOf('is_one_of'), ['a' => []]));
        $this->assertFalse($this->evaluator->evaluate($oneOf('is_not_one_of'), ['a' => ['Blue', 'GREEN']]));
        $this->assertTrue($this->evaluator->evaluate($oneOf('is_not_one_of'), ['a' => ['Blue', 'pink']]));
        $this->assertTrue($this->evaluator->evaluate($oneOf('is_not_one_of'), ['a' => []]));
    }

    public function testItComparesARelativeDateWithAMonthFieldByYearAndMonth(): void
    {
        $fields = ['m' => ['type' => 'month']];
        $rule = static fn (string $operator, int $days): array => ['match' => 'all', 'rules' => [
            ['field' => 'm', 'operator' => $operator, 'value_date' => ['days' => $days]],
        ]];
        $today = '2026-11-20';
        $this->assertTrue($this->evaluator->evaluate($rule('equals', 5), ['m' => '2026-11'], $fields, $today));
        $this->assertFalse($this->evaluator->evaluate($rule('greater_than', 5), ['m' => '2026-11'], $fields, $today));
        $this->assertTrue($this->evaluator->evaluate($rule('greater_than', 5), ['m' => '2026-12'], $fields, $today));
        $this->assertTrue($this->evaluator->evaluate($rule('less_than', 0), ['m' => '2026-10'], $fields, $today));
        $this->assertTrue($this->evaluator->evaluate($rule('equals', -31), ['m' => '2026-10'], $fields, $today));
        $this->assertTrue($this->evaluator->evaluate($rule('greater_or_equal', 41), ['m' => '2027-01'], $fields, $today));
        $this->assertFalse($this->evaluator->evaluate($rule('equals', 0), ['m' => ''], $fields, $today));
        $this->assertFalse($this->evaluator->evaluate($rule('equals', 0), ['m' => '2026-1'], $fields, $today));
    }

    public function testItComparesARelativeDateWithTheDatePartOfADatetimeAnswer(): void
    {
        $fields = ['t' => ['type' => 'datetime']];
        $rule = static fn (string $operator, int $days): array => ['match' => 'all', 'rules' => [
            ['field' => 't', 'operator' => $operator, 'value_date' => ['days' => $days]],
        ]];
        $today = '2026-11-07';
        $this->assertTrue($this->evaluator->evaluate($rule('equals', 0), ['t' => '2026-11-07T23:59'], $fields, $today));
        $this->assertTrue($this->evaluator->evaluate($rule('greater_than', 0), ['t' => '2026-11-08T00:00'], $fields, $today));
        $this->assertFalse($this->evaluator->evaluate($rule('greater_than', 0), ['t' => '2026-11-07T23:59'], $fields, $today));
        $this->assertTrue($this->evaluator->evaluate($rule('less_than', 0), ['t' => '2026-11-06T23:59'], $fields, $today));
        $this->assertFalse($this->evaluator->evaluate($rule('equals', 0), ['t' => '2026-11-07T25:99'], $fields, $today));
        $this->assertFalse($this->evaluator->evaluate($rule('equals', 0), ['t' => '2026-11-07 10:00'], $fields, $today));
    }
}
