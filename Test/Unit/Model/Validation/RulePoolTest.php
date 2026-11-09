<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Validation;

use Furan\FormBuilder\Api\ValidationRuleInterface;
use Furan\FormBuilder\Model\Validation\RepeatAnalyzer;
use Furan\FormBuilder\Model\Validation\Rule\Email;
use Furan\FormBuilder\Model\Validation\Rule\Integer;
use Furan\FormBuilder\Model\Validation\Rule\MaxLength;
use Furan\FormBuilder\Model\Validation\Rule\MinLength;
use Furan\FormBuilder\Model\Validation\Rule\NumericRule;
use Furan\FormBuilder\Model\Validation\Rule\Pattern;
use Furan\FormBuilder\Model\Validation\Rule\Phone;
use Furan\FormBuilder\Model\Validation\Rule\Url;
use Furan\FormBuilder\Model\Validation\Rule\Alpha;
use Furan\FormBuilder\Model\Validation\Rule\Alphanumeric;
use Furan\FormBuilder\Model\Validation\PatternMatcher;
use Furan\FormBuilder\Model\Validation\RulePool;
use PHPUnit\Framework\TestCase;

class RulePoolTest extends TestCase
{
    public static function textPool(): RulePool
    {
        return new RulePool([
            'min_length' => new MinLength(),
            'max_length' => new MaxLength(),
            'pattern' => new Pattern(new PatternMatcher(new RepeatAnalyzer())),
            'alpha' => new Alpha(),
            'alphanumeric' => new Alphanumeric(),
            'numeric' => new NumericRule(),
            'integer' => new Integer(),
            'email' => new Email(),
            'url' => new Url(),
            'phone' => new Phone(),
        ]);
    }

    public function testItReturnsARegisteredRuleFromThePoolAndThrowsForUnknownCodes(): void
    {
        $rule = new MinLength();
        $pool = new RulePool(['min_length' => $rule]);

        $this->assertTrue($pool->has('min_length'));
        $this->assertFalse($pool->has('nope'));
        $this->assertSame($rule, $pool->get('min_length'));
        $this->assertSame(['min_length' => $rule], $pool->all());

        $this->expectException(\InvalidArgumentException::class);
        $pool->get('nope');
    }

    public function testItPassesEveryTextRuleCaseInTheSharedRulesFixture(): void
    {
        $pool = self::textPool();
        $cases = json_decode(
            (string)file_get_contents(__DIR__ . '/../../../fixtures/rules.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $perRule = [];
        foreach ($cases as $i => $case) {
            if (!$pool->has($case['rule'])) {
                continue; // not a text rule; covered by its own type's tests
            }
            $perRule[$case['rule']] = ($perRule[$case['rule']] ?? 0) + 1;
            $rule = $pool->get($case['rule']);
            $this->assertSame(
                $case['expected'],
                $rule->validate($case['value'], $case['ruleValue'], ['values' => $case['values']]),
                sprintf('case #%d %s %s', $i, $case['rule'], json_encode($case['value']))
            );
        }
        foreach (array_keys($pool->all()) as $code) {
            $this->assertGreaterThanOrEqual(4, $perRule[$code] ?? 0, $code);
        }
    }

    public function testItCountsUnicodeCharactersForLengthRules(): void
    {
        $this->assertTrue((new MinLength())->validate('Zoë', 3, []));
        $this->assertFalse((new MinLength())->validate('Zoë', 4, []));
        $this->assertTrue((new MaxLength())->validate("a\u{1F600}", 2, []));
        $this->assertFalse((new MaxLength())->validate("a\u{1F600}b", 2, []));
    }

    public function testItFormatsDefaultMessagesWithTheRuleValue(): void
    {
        $this->assertSame('Please enter at least 3 characters.', (new MinLength())->getDefaultMessage(3));
        $this->assertSame('Please enter no more than 5 characters.', (new MaxLength())->getDefaultMessage(5));
        $this->assertSame('Please match the requested format.', (new Pattern(new PatternMatcher(new RepeatAnalyzer())))->getDefaultMessage('a'));
        $this->assertSame('Please use letters only.', (new Alpha())->getDefaultMessage(null));
        $this->assertSame('Please use letters and numbers only.', (new Alphanumeric())->getDefaultMessage(null));
        $this->assertSame('Please enter a valid number.', (new NumericRule())->getDefaultMessage(null));
        $this->assertSame('Please enter a whole number.', (new Integer())->getDefaultMessage(null));
        $this->assertSame('Please enter a valid email address.', (new Email())->getDefaultMessage(null));
        $this->assertSame(
            'Please enter a valid URL starting with http:// or https://.',
            (new Url())->getDefaultMessage(null)
        );
        $this->assertSame('Please enter a valid phone number.', (new Phone())->getDefaultMessage(null));
        $this->assertInstanceOf(ValidationRuleInterface::class, new Phone());
    }
}
