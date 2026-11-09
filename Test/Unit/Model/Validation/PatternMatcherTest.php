<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Validation;

use Furan\FormBuilder\Model\Validation\PatternMatcher;
use Furan\FormBuilder\Model\Validation\RepeatAnalyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PatternMatcherTest extends TestCase
{
    private PatternMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new PatternMatcher(new RepeatAnalyzer());
    }

    public function testItMatchesAnAnchoredPatternAgainstTheWholeValue(): void
    {
        $this->assertTrue($this->matcher->matches('[a-z]+', 'abc'));
        $this->assertFalse($this->matcher->matches('[a-z]+', 'abc1'));
        $this->assertFalse($this->matcher->matches('[a-z]+', "abc\n"));
        $this->assertTrue($this->matcher->matches('a|b', 'b'));
        $this->assertFalse($this->matcher->matches('a|b', 'ab'));
    }

    public function testItReturnsFalseWhenThePatternFailsToCompile(): void
    {
        $this->assertFalse($this->matcher->matches('(unclosed', 'x'));
        $this->assertFalse($this->matcher->isSafe('(unclosed'));
    }

    public function testItRestoresTheBacktrackLimitAfterMatching(): void
    {
        $before = ini_get('pcre.backtrack_limit');
        $this->matcher->matches('(a|aa)+b', str_repeat('a', 5000));
        $this->assertSame($before, ini_get('pcre.backtrack_limit'));
    }

    public function testItRejectsPatternsWithNestedQuantifiersAsUnsafe(): void
    {
        $this->assertFalse($this->matcher->isSafe('(a+)+'));
        $this->assertFalse($this->matcher->isSafe('(a*)*b'));
        $this->assertFalse($this->matcher->isSafe('(\d+){2,}'));
        $this->assertTrue($this->matcher->isSafe('[a-z]+\d{3}'));
        $this->assertTrue($this->matcher->isSafe('(ab)+'));
    }

    public function testItClassifiesTheSharedPatternSafetyFixture(): void
    {
        $fixture = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/pattern-safety.json'), true);
        foreach ($fixture['safe'] as $pattern) {
            $this->assertTrue($this->matcher->isSafe($pattern), 'safe: ' . $pattern);
        }
        foreach ($fixture['unsafe'] as $pattern) {
            $this->assertFalse($this->matcher->isSafe($pattern), 'unsafe: ' . $pattern);
        }
    }

    public function testItRejectsAdjacentOverlappingRepeatsThatBacktrackCatastrophically(): void
    {
        $this->assertFalse($this->matcher->isSafe('(\d*)(\d*)(\d*)(\d*)(\d*)(\d*)(\d*)(\d*)x'));
        $this->assertFalse($this->matcher->isSafe('\d*\d*'));
        $this->assertTrue($this->matcher->isSafe('[a-z]+\d+'));
    }

    public function testItRejectsBackreferences(): void
    {
        $this->assertFalse($this->matcher->isSafe('(a)\1'));
        $this->assertFalse($this->matcher->isSafe('(?<n>a)\k<n>'));
    }

    public function testItRejectsPatternsLongerThan256CharactersAsUnsafe(): void
    {
        $this->assertTrue($this->matcher->isSafe(str_repeat('a', 256)));
        $this->assertFalse($this->matcher->isSafe(str_repeat('a', 257)));
        $this->assertFalse($this->matcher->isSafe("a\x01b"));
    }

    public function testItRejectsPatternsThatEscapeTheAnchoringGroup(): void
    {
        $this->assertFalse($this->matcher->isSafe('x)|(?:.*'));
        $this->assertFalse($this->matcher->isSafe('a)(b'));
    }

    public function testItRejectsAlternationInsideAQuantifiedGroup(): void
    {
        $this->assertFalse($this->matcher->isSafe('(a|a)+'));
        $this->assertFalse($this->matcher->isSafe('(?:a|b)*'));
        $this->assertFalse($this->matcher->isSafe('(a|b){2,}'));
        $this->assertTrue($this->matcher->isSafe('(a|b)'));
        $this->assertTrue($this->matcher->isSafe('a|b'));
    }

    #[DataProvider('pcreOnlyPatterns')]
    public function testItRejectsPcreOnlySyntaxThatJavascriptCannotRun(string $pattern): void
    {
        $this->assertFalse($this->matcher->isSafe($pattern), $pattern);
    }

    public static function pcreOnlyPatterns(): array
    {
        return [
            'inline modifier' => ['(?i)abc'],
            'inline modifier x' => ['(?x)a b'],
            'atomic group' => ['(?>a+)b'],
            'possessive plus' => ['a++'],
            'possessive star' => ['a*+'],
            'possessive optional' => ['a?+'],
            'possessive brace' => ['a{2}+'],
            'start anchor' => ['\\Aabc'],
            'end anchor Z' => ['abc\\Z'],
            'end anchor z' => ['abc\\z'],
            'G' => ['\\Gabc'],
            'h' => ['\\h'],
            'R' => ['\\R'],
            'K' => ['a\\Kb'],
            'Q' => ['\\Qa.b\\E'],
            'posix class' => ['[[:alpha:]]+'],
            'identity escape letter' => ['\\y'],
            'identity escape in class' => ['[\\a]'],
        ];
    }

    public function testItStillAcceptsCommonSafePatterns(): void
    {
        foreach ([
            '[A-Z]{2}\\d{4}', '^\\+?[0-9 ()-]{7,20}$', '(?:ab)+', '(?=a)a', '(?!b)a', '(?<=a)b', '(?<!a)b',
            '(?<name>ab)c', '\\w+@\\w+\\.\\w{2,}', '\\bfoo\\b', '\\.\\-\\/', '[\\w.-]+', 'a+?', '\\p{L}+',
            '\\x41\\n\\t', '(a|b)c', 'colou?r',
        ] as $pattern) {
            $this->assertTrue($this->matcher->isSafe($pattern), $pattern);
        }
    }

    public function testItRejectsPcreVerbsThatEscapeTheAnchors(): void
    {
        foreach (['a(*ACCEPT)b', '(*F)', 'a(*COMMIT)', '(*SKIP)x', '(*UTF8)a', '(a(*ACCEPT))'] as $pattern) {
            $this->assertFalse($this->matcher->isSafe($pattern), $pattern);
        }
        $this->assertTrue($this->matcher->isSafe('\\(*\\)'));
        $this->assertTrue($this->matcher->isSafe('[(*]+'));
    }

    public function testItRejectsJavascriptIncompatibleBraceEscapesAndOpenQuantifiers(): void
    {
        foreach (['\\x{41}', '\\x{263A}+', 'a{,3}', '\\d{,5}'] as $pattern) {
            $this->assertFalse($this->matcher->isSafe($pattern), $pattern);
        }
        $this->assertTrue($this->matcher->isSafe('\\x41{2}'));
        $this->assertTrue($this->matcher->isSafe('a{1,3}'));
    }
}
