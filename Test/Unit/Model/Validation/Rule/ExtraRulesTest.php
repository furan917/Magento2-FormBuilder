<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Validation\Rule;

use Furan\FormBuilder\Model\Validation\Rule\Matches;
use Furan\FormBuilder\Model\Validation\Rule\Max;
use Furan\FormBuilder\Model\Validation\Rule\MaxDate;
use Furan\FormBuilder\Model\Validation\Rule\MaxSelected;
use Furan\FormBuilder\Model\Validation\Rule\Min;
use Furan\FormBuilder\Model\Validation\Rule\MinDate;
use Furan\FormBuilder\Model\Validation\Rule\MinSelected;
use Furan\FormBuilder\Model\Validation\RulePool;
use PHPUnit\Framework\TestCase;

class ExtraRulesTest extends TestCase
{
    private function pool(): RulePool
    {
        return new RulePool([
            'min' => new Min(),
            'max' => new Max(),
            'min_date' => new MinDate(),
            'max_date' => new MaxDate(),
            'matches' => new Matches(),
            'min_selected' => new MinSelected(),
            'max_selected' => new MaxSelected(),
        ]);
    }

    public function testItPassesEveryNumericRuleCaseInTheSharedRulesFixture(): void
    {
        $pool = $this->pool();
        $cases = json_decode(
            (string)file_get_contents(__DIR__ . '/../../../../fixtures/rules.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $perRule = [];
        foreach ($cases as $i => $case) {
            if (!$pool->has($case['rule'])) {
                continue;
            }
            $perRule[$case['rule']] = ($perRule[$case['rule']] ?? 0) + 1;
            $this->assertSame(
                $case['expected'],
                $pool->get($case['rule'])->validate($case['value'], $case['ruleValue'], ['values' => $case['values']]),
                sprintf('case #%d %s %s', $i, $case['rule'], json_encode($case['value']))
            );
        }
        foreach (array_keys($pool->all()) as $code) {
            $this->assertGreaterThanOrEqual(4, $perRule[$code] ?? 0, $code);
        }
    }

    public function testItRejectsNonNumericValuesForMinAndMax(): void
    {
        $this->assertFalse((new Min())->validate('abc', 1, []));
        $this->assertFalse((new Max())->validate('abc', 10, []));
        $this->assertFalse((new Min())->validate(['5'], 1, []));
        $this->assertTrue((new Min())->validate('-1.5', -2, []));
        $this->assertTrue((new Max())->validate('10', 10, []));
    }

    public function testItComparesDatesAndDatetimesByDatePart(): void
    {
        $this->assertTrue((new MinDate())->validate('2024-05-01T08:00', '2024-05-01', []));
        $this->assertFalse((new MinDate())->validate('2024-04-30', '2024-05-01', []));
        $this->assertTrue((new MaxDate())->validate('2024-05-01T23:59', '2024-05-01', []));
        $this->assertFalse((new MaxDate())->validate('2024-05-02', '2024-05-01', []));
        $this->assertFalse((new MinDate())->validate('garbage', '2024-05-01', []));
        $this->assertFalse((new MaxDate())->validate('garbage', '2024-05-01', []));
    }

    public function testItMatchesAnotherFieldValueExactly(): void
    {
        $ctx = ['values' => ['email' => 'A@b.com']];
        $this->assertTrue((new Matches())->validate('A@b.com', 'email', $ctx));
        $this->assertFalse((new Matches())->validate('a@b.com', 'email', $ctx));
        $this->assertFalse((new Matches())->validate('x', 'email', []));
    }

    public function testItCountsSelectedOptionsForArrayValues(): void
    {
        $this->assertTrue((new MinSelected())->validate(['a', 'b'], 2, []));
        $this->assertFalse((new MinSelected())->validate(['a'], 2, []));
        $this->assertTrue((new MaxSelected())->validate(['a', 'b'], 2, []));
        $this->assertFalse((new MaxSelected())->validate(['a', 'b', 'c'], 2, []));
    }

    public function testItTreatsASingleStringAsOneSelectedOption(): void
    {
        $this->assertTrue((new MinSelected())->validate('a', 1, []));
        $this->assertFalse((new MinSelected())->validate('a', 2, []));
        $this->assertFalse((new MinSelected())->validate('', 1, []));
        $this->assertTrue((new MaxSelected())->validate('a', 1, []));
        $this->assertTrue((new MaxSelected())->validate('', 0, []));
    }

    public function testItFormatsDefaultMessagesWithTheRuleValue(): void
    {
        $this->assertSame('Please enter a value of at least 5.', (new Min())->getDefaultMessage(5));
        $this->assertSame('Please enter a value of no more than 9.', (new Max())->getDefaultMessage(9));
        $this->assertSame('Please choose a date on or after 2024-01-01.', (new MinDate())->getDefaultMessage('2024-01-01'));
        $this->assertSame('Please choose a date on or before 2024-01-01.', (new MaxDate())->getDefaultMessage('2024-01-01'));
        $this->assertSame('This must match the email field.', (new Matches())->getDefaultMessage('email'));
        $this->assertSame('Please choose at least 2 options.', (new MinSelected())->getDefaultMessage(2));
        $this->assertSame('Please choose no more than 3 options.', (new MaxSelected())->getDefaultMessage(3));
    }
}
