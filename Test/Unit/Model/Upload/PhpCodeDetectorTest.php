<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Upload;

use Furan\FormBuilder\Model\Upload\PhpCodeDetector;
use PHPUnit\Framework\TestCase;

class PhpCodeDetectorTest extends TestCase
{
    private const IMAGE = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x01";

    private function detects(string $bytes): bool
    {
        return (new PhpCodeDetector())->scan($bytes, strlen($bytes), true);
    }

    public function testItFindsShortTagCodeInEveryShapePhpWouldRun(): void
    {
        $payloads = [
            '<?=system($_GET[0])?>', '<?= $x ?>', '<?=`id`?>', '<?=(system)(\'id\')?>', '<?=\\ns\\f()?>',
            "<?\nsystem('id') ?>", '<?=(1)?>',
            // Bytes PHP accepts inside comments, strings and heredocs, used to break simpler signatures.
            "<?=system/*\x00\x01\xff*/('id')?>",
            "<?=\"\x00\x02\".system('id')?>",
            "<?='\x00'.`id`?>",
            "<?=<<<A\n\x00\x07\nA . system('id')?>",
            "<?=system('id') # \x00\x01 ?>",
            "<?=system('id')// \x00\n?>",
            "<?=\xc3\xa9(1)?>",
        ];
        foreach ($payloads as $code) {
            self::assertTrue($this->detects(self::IMAGE . "\x00\x9c" . $code . "\x00\xfe"), bin2hex($code));
        }
    }

    public function testCodeRunningToTheEndOfTheFileNeedsNoClosingTag(): void
    {
        self::assertTrue($this->detects(self::IMAGE . "\x00<?= system(\$_GET[0]);\n"));
        self::assertTrue($this->detects(self::IMAGE . "\x00<?=system('id'); /* \x00 */"));
    }

    public function testItIgnoresTagBytesThatCannotParseAsCode(): void
    {
        $xmp = '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta/><?xpacket end="w"?>';
        foreach (["<?=\x00?>", "<?=abc\x01def?>", "<? \x1f", $xmp, "<?xml version=\"1.0\"?>", "<?=<<<\x00"] as $bytes) {
            self::assertFalse($this->detects(self::IMAGE . $bytes . "\x00\xfe"), bin2hex($bytes));
        }
    }

    public function testShortOpenTagsCountWhateverTheServerSetting(): void
    {
        self::assertTrue($this->detects(self::IMAGE . "\x00<? system('id') ?>\x00"));
    }

    public function testAnOverLongWalkFailsClosed(): void
    {
        $long = "<?='" . str_repeat('a', PhpCodeDetector::MAX_WALK + 10);
        self::assertTrue((new PhpCodeDetector())->scan($long . "\x00", strlen($long), false));
    }

    public function testItScansWholeFilesAcrossChunkBoundaries(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ffb');
        // Payload straddles the 1 MB read boundary.
        file_put_contents($path, str_repeat("\x00", 1048576 - 6) . "<?=system('id')?>" . "\x00");
        self::assertTrue((new PhpCodeDetector())->containsCode($path));
        file_put_contents($path, str_repeat("\x00", 3 * 1048576));
        self::assertFalse((new PhpCodeDetector())->containsCode($path));
        unlink($path);
    }

    public function testCompressedLookingDataPasses(): void
    {
        // Deterministic stand-in for compressed image data, with near misses appended.
        $chunks = [];
        for ($i = 0; $i < 2 * 1048576 / 32; $i++) {
            $chunks[] = hash('sha256', 'ffb-detector-' . $i, true);
        }
        $data = self::IMAGE . implode('', $chunks) . "<?=a\x00<?=(\x9c<? Q\x01\xfe";
        self::assertFalse($this->detects($data));
    }
}
