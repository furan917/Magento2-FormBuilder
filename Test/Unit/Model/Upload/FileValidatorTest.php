<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Upload;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Upload\ExtensionList;
use Furan\FormBuilder\Model\Upload\FileValidator;
use Furan\FormBuilder\Model\Upload\PhpCodeDetector;
use PHPUnit\Framework\TestCase;

class FileValidatorTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var string[] */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function tmp(string $content): string
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'ffb');
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;

        return $path;
    }

    private function validator(int $maxMb = 5, array $extensions = ['pdf', 'png', 'jpg', 'txt']): FileValidator
    {
        $config = $this->createStub(Config::class);
        $config->method('getMaxUploadMb')->willReturn($maxMb);
        $config->method('getAllowedExtensions')->willReturn($extensions);

        return new class ($config, new PhpCodeDetector(), new ExtensionList()) extends FileValidator {
            protected function isUploadedFile(string $path): bool
            {
                return true;
            }
        };
    }

    private function upload(string $name, string $content, int $error = UPLOAD_ERR_OK): array
    {
        $path = $this->tmp($content);

        return ['name' => $name, 'tmp_name' => $path, 'size' => strlen($content), 'error' => $error, 'type' => 'x'];
    }

    public function testItAcceptsAnAllowedFileWhoseContentMatchesItsExtension(): void
    {
        $v = $this->validator();
        self::assertNull($v->validate($this->upload('Photo.PNG', (string)base64_decode(self::PNG)), []));
        self::assertNull($v->validate($this->upload('a.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n"), []));
        self::assertNull($v->validate($this->upload('a.txt', "hello world\n"), ['settings' => ['extensions' => ['txt']]]));
    }

    public function testItRejectsAFileWhoseSniffedMimeDoesNotMatchTheExtension(): void
    {
        $v = $this->validator();
        self::assertSame(
            'This file type is not allowed.',
            $v->validate($this->upload('a.png', "%PDF-1.4\n1 0 obj<<>>endobj\n"), [])
        );
        self::assertSame(
            'This file type is not allowed.',
            $v->validate($this->upload('a.pdf', (string)base64_decode(self::PNG)), [])
        );
    }

    public function testItRejectsExtensionsOutsideTheFieldAndGlobalAllowlists(): void
    {
        $v = $this->validator();
        $msg = 'This file type is not allowed.';
        self::assertSame($msg, $v->validate($this->upload('a.php', 'x'), []));
        self::assertSame($msg, $v->validate($this->upload('a', 'x'), []));
        self::assertSame($msg, $v->validate($this->upload('a.txt', 'hello'), ['settings' => ['extensions' => ['pdf']]]));
        self::assertSame($msg, $v->validate($this->upload("a.txt\0.png", 'hello'), []));
        self::assertSame($msg, $v->validate($this->upload('../a.txt', 'hello'), []));
        self::assertSame($msg, $v->validate($this->upload('x\\a.txt', 'hello'), []));
        // field list may not widen the global list
        self::assertSame($msg, $v->validate($this->upload('a.csv', 'a,b'), ['settings' => ['extensions' => ['csv']]]));
        $php = $this->validator(5, ['php', 'txt']);
        self::assertSame($msg, $php->validate($this->upload('a.php', 'hello'), []));
    }

    public function testItMatchesFieldExtensionsWrittenWithDotsCapitalsOrSpaces(): void
    {
        $v = $this->validator();
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\n";

        self::assertNull($v->validate($this->upload('a.pdf', $pdf), ['settings' => ['extensions' => ['.pdf']]]));
        self::assertNull($v->validate($this->upload('a.pdf', $pdf), ['settings' => ['extensions' => [' .PDF ']]]));
        self::assertNull($v->validate($this->upload('a.txt', 'hello'), ['settings' => ['extensions' => ['Txt', '.png']]]));
    }

    public function testItNeverWidensTheStoreAllowlistWithFieldExtensions(): void
    {
        $v = $this->validator(5, ['pdf', 'txt']);
        $png = (string)base64_decode(self::PNG);

        self::assertSame(
            'This file type is not allowed.',
            $v->validate($this->upload('a.png', $png), ['settings' => ['extensions' => ['.png', 'pdf']]])
        );
    }

    public function testItRefusesEveryFileWhenTheFieldAndStoreListsShareNoExtension(): void
    {
        $v = $this->validator(5, ['pdf', 'txt']);

        self::assertSame(
            'This file type is not allowed.',
            $v->validate($this->upload('a.txt', 'hello'), ['settings' => ['extensions' => ['.png', 'JPG']]])
        );
    }

    public function testItUsesTheStoreAllowlistWhenTheFieldListHasOnlyBlankEntries(): void
    {
        $v = $this->validator();

        self::assertNull($v->validate($this->upload('a.txt', 'hello'), ['settings' => ['extensions' => [' ', '.', ['x']]]]));
    }

    public function testItRejectsFilesOverTheSizeLimit(): void
    {
        $v = $this->validator(1);
        $big = str_repeat('a', 1024 * 1024 + 1);
        self::assertSame('The file is too large.', $v->validate($this->upload('a.txt', $big), []));
        $fieldLimit = ['settings' => ['max_size_mb' => 1]];
        self::assertNull($this->validator(5)->validate($this->upload('a.txt', 'small'), $fieldLimit));
        self::assertSame(
            'The file is too large.',
            $this->validator(5)->validate($this->upload('a.txt', $big), $fieldLimit)
        );
        // field limit cannot exceed the global cap
        self::assertSame(
            'The file is too large.',
            $v->validate($this->upload('a.txt', $big), ['settings' => ['max_size_mb' => 50]])
        );
    }

    public function testItRejectsFilesContainingPhpOrScriptMarkers(): void
    {
        $v = $this->validator();
        foreach (['<?php echo 1;', 'x <SCRIPT>alert(1)', 'abc <HTML>'] as $content) {
            self::assertSame(
                'This file type is not allowed.',
                $v->validate($this->upload('a.txt', $content), [])
            );
        }
    }

    public function testItMapsUploadErrorCodesToMessages(): void
    {
        $v = $this->validator();
        self::assertSame('The file is too large.', $v->validate($this->upload('a.txt', 'x', UPLOAD_ERR_INI_SIZE), []));
        self::assertSame('The file is too large.', $v->validate($this->upload('a.txt', 'x', UPLOAD_ERR_FORM_SIZE), []));
        self::assertSame('Please choose a file.', $v->validate($this->upload('a.txt', 'x', UPLOAD_ERR_NO_FILE), []));
        self::assertSame(
            'The file could not be uploaded.',
            $v->validate($this->upload('a.txt', 'x', UPLOAD_ERR_PARTIAL), [])
        );
    }

    public function testItRejectsFilesThatWereNotUploadedViaHttp(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getMaxUploadMb')->willReturn(5);
        $config->method('getAllowedExtensions')->willReturn(['txt']);
        $v = new FileValidator($config, new PhpCodeDetector(), new ExtensionList());
        self::assertSame('The file could not be uploaded.', $v->validate($this->upload('a.txt', 'hi'), []));
    }

    public function testItFindsCodeHiddenAnywhereInAPolyglotImage(): void
    {
        $v = $this->validator();
        $png = (string)base64_decode(self::PNG);
        $padding = str_repeat("\x00", 5000);
        foreach (['<?php system($_GET[1]);', '<SCRIPT>alert(1)</script>', '__HALT_COMPILER();'] as $code) {
            self::assertSame(
                'This file type is not allowed.',
                $v->validate($this->upload('photo.png', $png . $padding . $code), []),
                $code
            );
        }
        // A marker split across the 1 MB read boundary is still found.
        $split = $png . str_repeat("\x00", 1048576 - strlen($png) - 3) . '<?php';
        self::assertSame('This file type is not allowed.', $v->validate($this->upload('photo.png', $split), []));
    }

    public function testItRefusesExecutableOrDisguisedFileNames(): void
    {
        $v = $this->validator(5, ['pdf', 'png', 'jpg', 'txt']);
        $png = (string)base64_decode(self::PNG);
        foreach ([
            'shell.php.png', 'shell.PHTML.png', 'x.phar.png', 'shell.png.php', 'shell.php', 'x.PHP',
            'shell.png.', '.htaccess', 'png', "cv\u{202E}gnp.php", "a\u{200B}.php.png", "shell.php\x00.png",
            "bad\xff.png",
        ] as $name) {
            self::assertSame('This file type is not allowed.', $v->validate($this->upload($name, $png), []), $name);
        }
        self::assertNull($v->validate($this->upload('Holiday.Photo.PNG', $png), []));
    }

    public function testItRefusesHtmlOrSvgRenamedAsText(): void
    {
        $v = $this->validator();
        self::assertSame('This file type is not allowed.', $v->validate($this->upload('a.txt', '<html><body>x'), []));
        self::assertSame(
            'This file type is not allowed.',
            $v->validate($this->upload('a.txt', '<svg xmlns="http://www.w3.org/2000/svg"><script>x</script></svg>'), [])
        );
    }

    public function testShortTagBytesInsideBinaryDataAreNotMistakenForCode(): void
    {
        $v = $this->validator();
        $png = (string)base64_decode(self::PNG);
        // Compressed image data is effectively random and regularly contains these three bytes.
        $photo = $png . random_bytes(3000) . "<?=\x00\x9c" . random_bytes(3000);
        $photo = (string)preg_replace('/<\?|<script|<html|__halt_compiler/i', 'xx', $photo);
        self::assertNull($v->validate($this->upload('photo.png', $photo), []));
        self::assertSame('This file type is not allowed.', $v->validate($this->upload('photo.png', $photo . '<?php'), []));
        self::assertSame('This file type is not allowed.', $v->validate($this->upload('notes.txt', "hello\n<?= `id` ?>\n"), []));
    }

    public function testItRejectsShortEchoCodeInEveryFileType(): void
    {
        // Planted-file chains (StyleSmuggler, Blockstrap) include an "image" with a short echo tag.
        $v = $this->validator();
        $png = (string)base64_decode(self::PNG);
        $codes = ['<?=system($_GET[0])?>', '<?= $x ?>', '<?=`id`?>', '<?=$_GET[0]?>', '<?=  _x()?>', '<?=(system)(\'id\')?>',
            '<?=\\ns\\f()?>', "<?\nsystem('id') ?>", '<?=(1)?>'];
        foreach ($codes as $code) {
            self::assertSame(
                'This file type is not allowed.',
                $v->validate($this->upload('photo.png', $png . str_repeat("\x00", 100) . $code), []),
                $code
            );
        }
        self::assertSame(
            'This file type is not allowed.',
            $v->validate($this->upload('a.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n<?=\$x?>"), [])
        );
        // No closing tag is needed when the code runs to the end of the file.
        self::assertSame(
            'This file type is not allowed.',
            $v->validate($this->upload('photo.png', $png . "\x00\x9c<?= system(\$_GET[0]);\n"), [])
        );
    }

    public function testItAcceptsShortTagBytesThatCannotParseAsCode(): void
    {
        $v = $this->validator();
        $png = (string)base64_decode(self::PNG);
        $xmp = '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta/><?xpacket end="w"?>';
        foreach (["<?=\x00\x9c?>", "<?=abc\x01def?>", "<? \xff", $xmp] as $bytes) {
            self::assertNull($v->validate($this->upload('photo.png', $png . $bytes . "\x00\xfe"), []), bin2hex($bytes));
        }
    }

    public function testItAcceptsAFiveMegabyteRandomBufferThatOnlyLooksLikeShortTags(): void
    {
        // Deterministic stand-in for compressed image data: SHA-256 in counter mode.
        $chunks = [];
        for ($i = 0; $i < 5 * 1048576 / 32; $i++) {
            $chunks[] = hash('sha256', 'ffb-seed-' . $i, true);
        }
        $png = (string)base64_decode(self::PNG);
        // Short-tag fragments random data produces by chance; an "any letter" pattern would refuse them.
        $data = $png . implode('', $chunks) . '<?=a' . "\x00" . '<?=(' . "\x9c" . '<?= Q' . "\x01";

        self::assertNull($this->validator(6)->validate($this->upload('photo.png', $data), []));
    }

    public function testItRefusesExecutableExtensionsPaddedWithWhitespace(): void
    {
        $v = $this->validator();
        $png = (string)base64_decode(self::PNG);
        foreach (['x.php .png', "x.php\u{00A0}.png", "x. php.png", "x.phtml\u{3000}.png", "x.php\t.png"] as $name) {
            self::assertSame('This file type is not allowed.', $v->validate($this->upload($name, $png), []), $name);
        }
    }

    public function testItAcceptsOfficeDocumentsOnlyWithTheirPackageMarker(): void
    {
        $v = $this->validator(5, ['docx', 'xlsx', 'odt', 'ods']);
        $msg = 'This file type is not allowed.';
        self::assertNull($v->validate($this->upload('a.docx', $this->zip(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w/>'])), []));
        self::assertNull($v->validate($this->upload('a.xlsx', $this->zip(['[Content_Types].xml' => '<Types/>', 'xl/workbook.xml' => '<w/>'])), []));
        self::assertNull($v->validate($this->upload('a.odt', $this->zip(['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => '<c/>'])), []));
        self::assertNull($v->validate($this->upload('a.ods', $this->zip(['mimetype' => 'application/vnd.oasis.opendocument.spreadsheet', 'content.xml' => '<c/>'])), []));
        self::assertSame($msg, $v->validate($this->upload('a.docx', $this->zip(['readme.txt' => 'hello'])), []));
        self::assertSame($msg, $v->validate($this->upload('a.odt', $this->zip(['readme.txt' => 'hello'])), []));
        self::assertSame($msg, $v->validate($this->upload('a.ods', $this->zip(['[Content_Types].xml' => '<Types/>'])), []));
        self::assertSame($msg, $v->validate($this->upload('a.odt', $this->zip(['mimetype' => 'application/vnd.oasis.opendocument.spreadsheet'])), []));
    }

    public function testItNoLongerSupportsMacroCapableLegacyOfficeFormats(): void
    {
        self::assertNotContains('doc', FileValidator::supportedExtensions());
        self::assertNotContains('xls', FileValidator::supportedExtensions());
        self::assertContains('docx', FileValidator::supportedExtensions());
        $defaults = (string)simplexml_load_file(__DIR__ . '/../../../../etc/config.xml')
            ->xpath('//uploads/allowed_extensions')[0];
        self::assertSame([], array_diff(explode(',', $defaults), FileValidator::supportedExtensions()));
    }

    /** @param array<string, string> $entries */
    private function zip(array $entries): string
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'ffbz');
        $this->tmpFiles[] = $path;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return (string)file_get_contents($path);
    }
}
