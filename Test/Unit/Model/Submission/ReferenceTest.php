<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Model\Submission\Reference;
use PHPUnit\Framework\TestCase;

class ReferenceTest extends TestCase
{
    public function testItGeneratesAVersion7Uuid(): void
    {
        $ms = 1_793_000_000_123;
        $bytes = Reference::generate($ms);

        $this->assertSame(16, strlen($bytes));
        $this->assertSame(7, ord($bytes[6]) >> 4);
        $this->assertSame(0b10, ord($bytes[8]) >> 6);
        $this->assertSame($ms, (int)hexdec(bin2hex(substr($bytes, 0, 6))));
        $this->assertNotSame(Reference::generate($ms), $bytes, 'The random part differs between calls');
    }

    public function testItOrdersReferencesByCreationTime(): void
    {
        $earlier = Reference::generate(1_793_000_000_000);
        $later = Reference::generate(1_793_000_000_001);

        $this->assertLessThan(0, strcmp($earlier, $later));
        $this->assertLessThan(0, strcmp(Reference::toString($earlier), Reference::toString($later)));
    }

    public function testItFormatsAReferenceInHyphenatedLowerCase(): void
    {
        $bytes = hex2bin('018F3A5BC0DE7ABCB123456789ABCDEF');

        $this->assertSame('018f3a5b-c0de-7abc-b123-456789abcdef', Reference::toString($bytes));
    }

    public function testItParsesAReferenceWithoutHyphensAndInUpperCase(): void
    {
        $bytes = hex2bin('018F3A5BC0DE7ABCB123456789ABCDEF');

        $this->assertSame($bytes, Reference::parse('018F3A5BC0DE7ABCB123456789ABCDEF'));
        $this->assertSame($bytes, Reference::parse(' 018f3a5b-C0DE-7abc-b123-456789abcdef '));
    }

    public function testItRejectsAMalformedReference(): void
    {
        foreach (['', 'abc', str_repeat('g', 32), str_repeat('a', 31), str_repeat('a', 33), "018f3a5b-c0de-7abc-b123-456789abcde\0", '018f3a5b-c0de-7abc-b123-456789abcd%'] as $bad) {
            $this->assertNull(Reference::parse($bad), var_export($bad, true));
        }
        $this->assertSame('', Reference::toString('short'), 'Raw bytes of the wrong length are never echoed');
    }
}
