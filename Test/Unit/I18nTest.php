<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use Furan\FormBuilder\Model\Admin\BuilderSharedConfig;
use Furan\FormBuilder\Model\Schema\ContentTypes;
use PHPUnit\Framework\TestCase;

class I18nTest extends TestCase
{
    private const CSV = __DIR__ . '/../../i18n/en_US.csv';

    public function testItListsEveryTranslatablePhpAndTemplatePhraseInTheDictionary(): void
    {
        $dictionary = array_column($this->readCsv(), 0);
        $missing = [];
        foreach ($this->sourceFiles() as $file) {
            foreach ($this->extractPhrases((string)file_get_contents($file)) as $phrase) {
                if (!in_array($phrase, $dictionary, true)) {
                    $missing[$phrase] = $phrase . '  (' . substr($file, strlen(dirname(__DIR__, 2)) + 1) . ')';
                }
            }
        }

        $this->assertSame([], array_values($missing), 'Phrases missing from i18n/en_US.csv');
    }

    public function testItListsEverySystemConfigLabelAndCommentInTheDictionary(): void
    {
        $dictionary = array_column($this->readCsv(), 0);
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/adminhtml/system.xml');
        $missing = [];
        foreach (['label', 'comment'] as $tag) {
            foreach ($xml->xpath('//' . $tag) as $node) {
                $text = trim((string)$node);
                if ($text !== '' && !in_array($text, $dictionary, true)) {
                    $missing[$text] = $text;
                }
            }
        }

        $this->assertSame([], array_values($missing), 'system.xml texts missing from i18n/en_US.csv');
    }

    public function testItKeepsTheDictionaryFreeOfDuplicateSourcePhrases(): void
    {
        $sources = array_column($this->readCsv(), 0);

        $this->assertNotEmpty($sources);
        $this->assertSame([], array_keys(array_filter(array_count_values($sources), fn (int $n) => $n > 1)));
    }

    public function testItHasEveryContentTypeAndWidthLabelInEveryPack(): void
    {
        $widths = (new \ReflectionClassConstant(BuilderSharedConfig::class, 'WIDTHS'))->getValue();
        $labels = array_merge(array_values(ContentTypes::labels()), array_values($widths));
        $xml = (string)file_get_contents(dirname(__DIR__, 2) . '/view/adminhtml/ui_component/furan_formbuilder_form_listing.xml');
        $labels = array_merge($labels, ['Enabled']);
        $this->assertStringContainsString('translate="true">Enabled<', $xml);

        foreach (glob(dirname(__DIR__, 2) . '/i18n/*.csv') as $file) {
            $pack = [];
            $handle = fopen($file, 'r');
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $pack[$row[0]] = $row[1] ?? '';
            }
            fclose($handle);
            $missing = array_values(array_filter($labels, static fn (string $label): bool => !isset($pack[$label])));
            $this->assertSame([], $missing, basename($file) . ': labels missing');
        }
    }

    /**
     * @return string[][]
     */
    private function readCsv(): array
    {
        $this->assertFileExists(self::CSV);
        $rows = [];
        $handle = fopen(self::CSV, 'r');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($row !== [null]) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return string[]
     */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $relative = substr($path, strlen($root) + 1);
            if (preg_match('#^(Test|\.git)/#', $relative)) {
                continue;
            }
            if (preg_match('/\.(php|phtml)$/', $path)) {
                $files[] = $path;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return string[]
     */
    private function extractPhrases(string $code): array
    {
        $phrases = [];
        preg_match_all('/\b__\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/s', $code, $single);
        foreach ($single[1] as $phrase) {
            $phrases[] = str_replace(["\\'", '\\\\'], ["'", '\\'], $phrase);
        }
        preg_match_all('/\b__\(\s*"((?:[^"\\\\$]|\\\\.)*)"/s', $code, $double);
        foreach ($double[1] as $phrase) {
            $phrases[] = str_replace(['\\"', '\\\\'], ['"', '\\'], $phrase);
        }

        return $phrases;
    }
}
