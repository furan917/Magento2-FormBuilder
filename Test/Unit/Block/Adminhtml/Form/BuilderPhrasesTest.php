<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Adminhtml\Form;

use Furan\FormBuilder\Block\Adminhtml\Form\BuilderPhrases;
use PHPUnit\Framework\TestCase;

class BuilderPhrasesTest extends TestCase
{
    public function testEveryBuilderPhraseIsTranslatableAndNoneAreStale(): void
    {
        $used = [];
        foreach (glob(__DIR__ . '/../../../../../view/adminhtml/web/js/builder*.js') as $file) {
            preg_match_all("/\\b(?:tr|phrase)\\('((?:[^'\\\\]|\\\\.)*)'/", (string)file_get_contents($file), $matches);
            foreach ($matches[1] as $text) {
                $used[str_replace("\\'", "'", $text)] = true;
            }
        }
        $listed = (new BuilderPhrases())->all();
        $this->assertNotEmpty($used);
        $this->assertSame([], array_values(array_diff(array_keys($used), array_keys($listed))), 'Missing from BuilderPhrases');
        $this->assertSame([], array_values(array_diff(array_keys($listed), array_keys($used))), 'No longer used by the builder');
        foreach ($listed as $english => $translated) {
            $this->assertSame($english, $translated);
        }
    }

    public function testItMergesPhrasesAddedThroughDiIntoTheBuilderTranslations(): void
    {
        $all = (new BuilderPhrases(['Acme stars' => 'Acme stars', 'Add rule' => 'Hijacked', 'Acme list']))->all();
        $this->assertSame('Acme stars', $all['Acme stars']);
        $this->assertSame('Acme list', $all['Acme list']);
        $this->assertSame('Add rule', $all['Add rule']);
    }
}
