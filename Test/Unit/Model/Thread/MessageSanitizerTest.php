<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Thread;

use Furan\FormBuilder\Model\Thread\MessageSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MessageSanitizerTest extends TestCase
{
    #[DataProvider('cases')]
    public function testItCleansAMessageBody(string $in, string $expected): void
    {
        $this->assertSame($expected, (new MessageSanitizer())->clean($in));
    }

    public static function cases(): array
    {
        return [
            'plain text is kept' => ['Hello, world', 'Hello, world'],
            'invalid utf-8 is empty' => ["Hi \xC3\x28 there", ''],
            'nfc normalisation' => ["Cafe\u{0301}", "Caf\u{00E9}"],
            'crlf and cr become lf' => ["a\r\nb\rc", "a\nb\nc"],
            'tab becomes one space' => ["a\tb", 'a b'],
            'c0 controls removed' => ["a\x00b\x07c\x1Bd\x7F", 'abcd'],
            'c1 controls removed' => ["a\u{0085}b\u{009F}c", 'abc'],
            'bidi overrides removed' => ["user\u{202E}gpj.exe\u{202C} \u{2066}x\u{2069} \u{200E}\u{200F}\u{061C}y", 'usergpj.exe x y'],
            'zero width and invisible removed' => ["a\u{200B}bc\u{2060}d\u{2064}e\u{FEFF}f\u{00AD}g\u{180E}h", 'abcdefgh'],
            'zero-width non-joiner kept for Persian and Indic text' => ["\u{0645}\u{06CC}\u{200C}\u{062E}\u{0648}\u{0627}\u{0647}\u{0645}", "\u{0645}\u{06CC}\u{200C}\u{062E}\u{0648}\u{0627}\u{0647}\u{0645}"],
            'other format chars removed' => ["a\u{FFF9}b\u{206A}c", 'abc'],
            'zwj between letters removed' => ["a\u{200D}b", 'ab'],
            'lone zwj removed' => ["\u{200D}x\u{200D}", 'x'],
            'zwj family kept' => ["\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}", "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"],
            'zwj after skin tone kept' => ["\u{1F469}\u{1F3FD}\u{200D}\u{1F4BB}", "\u{1F469}\u{1F3FD}\u{200D}\u{1F4BB}"],
            'zwj after variation selector kept' => ["\u{2764}\u{FE0F}\u{200D}\u{1F525}", "\u{2764}\u{FE0F}\u{200D}\u{1F525}"],
            'rainbow flag kept' => ["\u{1F3F3}\u{FE0F}\u{200D}\u{1F308}", "\u{1F3F3}\u{FE0F}\u{200D}\u{1F308}"],
            'zwj before a letter removed' => ["\u{1F600}\u{200D}a", "\u{1F600}a"],
            'subdivision flag kept' => [
                "\u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}",
                "\u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}",
            ],
            'stray tag characters removed' => ["a\u{E0041}\u{E007F}b", 'ab'],
            'keycap kept' => ["1\u{FE0F}\u{20E3}", "1\u{FE0F}\u{20E3}"],
            'zalgo capped at three marks' => ["q\u{0300}\u{0301}\u{0302}\u{0303}\u{0304}x", "q\u{0300}\u{0301}\u{0302}x"],
            'leading marks capped' => ["\u{0300}\u{0301}\u{0302}\u{0303}a", "\u{0300}\u{0301}\u{0302}a"],
            'trailing spaces trimmed per line' => ["a  \nb\u{00A0}\u{3000}\nc", "a\nb\nc"],
            'three newlines collapse to two' => ["a\n\n\n\n\nb", "a\n\nb"],
            'whitespace-only lines collapse' => ["a\n \n\t\n  \nb", "a\n\nb"],
            'whole body trimmed' => ["\n\n  hello  \n\n", 'hello'],
            'unicode spaces only is empty' => ["\u{00A0}\u{3000}\u{2003}\n", ''],
            'invisible only is empty' => ["\u{200B}\u{202E}\u{FEFF}", ''],
            'line separators become newlines' => ["a\u{2028}b\u{2029}c", "a\nb\nc"],
            'indentation kept' => ["- a\n  - b", "- a\n  - b"],
        ];
    }

    public function testItCapsTheBodyAtTwoHundredLinesJoiningTheRestIntoTheLast(): void
    {
        $lines = array_map(static fn (int $i): string => 'l' . $i, range(1, 205));

        $out = explode("\n", (new MessageSanitizer())->clean(implode("\n", $lines)));

        $this->assertCount(200, $out);
        $this->assertSame('l199', $out[198]);
        $this->assertSame('l200 l201 l202 l203 l204 l205', $out[199]);
    }

    public function testItKeepsExactlyTwoHundredLines(): void
    {
        $body = implode("\n", array_fill(0, 200, 'x'));

        $this->assertSame($body, (new MessageSanitizer())->clean($body));
    }

    public function testItSkipsBlankLinesWhenJoiningTheOverflow(): void
    {
        $body = implode("\n", array_fill(0, 199, 'x')) . "\nlast\n\nmore";

        $out = explode("\n", (new MessageSanitizer())->clean($body));

        $this->assertCount(200, $out);
        $this->assertSame('last more', $out[199]);
    }
}
