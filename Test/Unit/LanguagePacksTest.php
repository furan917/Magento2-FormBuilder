<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use PHPUnit\Framework\TestCase;

class LanguagePacksTest extends TestCase
{
    /** @return array<string, string> */
    private function read(string $file): array
    {
        $rows = [];
        $handle = fopen($file, 'rb');
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $this->assertCount(2, $row, basename($file) . ': every line needs exactly two columns');
            $rows[$row[0]] = $row[1];
        }
        fclose($handle);
        return $rows;
    }

    /** @return string[] */
    private function placeholders(string $text): array
    {
        preg_match_all('/%\w+|\{-?\w\}/', $text, $m);
        $found = $m[0];
        sort($found);
        return $found;
    }

    public function testEveryPackTranslatesEveryPhraseAndKeepsItsPlaceholders(): void
    {
        $dir = __DIR__ . '/../../i18n';
        $source = $this->read($dir . '/en_US.csv');
        $packs = array_diff(glob($dir . '/*.csv'), [$dir . '/en_US.csv']);
        $this->assertNotEmpty($packs);
        foreach ($packs as $file) {
            $pack = $this->read($file);
            $name = basename($file);
            $this->assertSame([], array_values(array_diff(array_keys($source), array_keys($pack))), $name . ': missing phrases');
            $this->assertSame([], array_values(array_diff(array_keys($pack), array_keys($source))), $name . ': unknown phrases');
            foreach ($pack as $phrase => $translation) {
                $this->assertNotSame('', trim($translation), $name . ': empty translation for ' . $phrase);
                $this->assertSame($this->placeholders($phrase), $this->placeholders($translation), $name . ': placeholders in ' . $phrase);
            }
        }
    }

    public function testItLeavesNoUntranslatedRowsOutsideTheAllowlist(): void
    {
        $dir = __DIR__ . '/../../i18n';
        $allow = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/i18n-identical-allowlist.json'), true);
        foreach (array_diff(glob($dir . '/*.csv'), [$dir . '/en_US.csv']) as $file) {
            $locale = basename($file, '.csv');
            $identical = [];
            foreach ($this->read($file) as $phrase => $translation) {
                if ($phrase === $translation) {
                    $identical[] = $phrase;
                }
            }
            $extra = array_values(array_diff($identical, $allow[$locale] ?? []));
            $this->assertSame([], $extra, $locale . ': untranslated (translate, or add to the allowlist if identical by design)');
        }
    }
}
