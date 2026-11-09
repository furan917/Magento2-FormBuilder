<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use Furan\FormBuilder\Model\Validation\BuiltInRules;
use PHPUnit\Framework\TestCase;

/** __($variable) hides phrases from the literal scan of I18nTest; every such call site must name where its phrases are tested. */
class I18nDynamicPhrasesGuardTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const BUILT_IN_RULES = 'BuiltInRules texts, checked against the dictionary below';
    private const CONTENT_TYPES = 'ContentTypes::labels(), checked by I18nTest and I18nUnusedPhrasesTest';
    private const STOCK_TEXTS = 'stock form texts, checked against the dictionary below';
    private const TRANSLATED = 'the argument is already a translated string, so the call changes nothing more';

    /** file => [first argument => where the phrases come from] */
    private const SITES = [
        'Block/Adminhtml/Form/BuilderPhrases.php' => ['$english' => 'English texts added by extensions through di.xml; the extension ships the translation'],
        'Block/Adminhtml/Rule/Edit.php' => ["\$source['label']" => self::BUILT_IN_RULES, "\$source['message']" => self::BUILT_IN_RULES],
        'Block/Adminhtml/Rule/Index.php' => ["\$rule['group']" => self::BUILT_IN_RULES, "\$rule['label']" => self::BUILT_IN_RULES],
        'Model/Admin/BuilderSharedConfig.php' => ['$label' => self::CONTENT_TYPES . ' and the WIDTHS constant'],
        'Model/Config/Backend/TileUrl.php' => ['$error' => self::TRANSLATED],
        'Model/FieldGroup/FieldGroupManager.php' => ['implode(' => self::TRANSLATED, '$this->nameTakenError(' => self::TRANSLATED],
        'Model/FieldType/Hidden.php' => ['$label' => 'labels of extra sources from di.xml; the extension ships the translation'],
        'Model/Form/ScheduleGate.php' => ['$stock' => self::STOCK_TEXTS],
        'Model/I18n/Translator.php' => ['$stock' => self::STOCK_TEXTS, 'self::STOCK_SUMMARY_TITLE' => self::STOCK_TEXTS],
        'Model/Storefront/ConfigBuilder.php' => ['$default' => self::STOCK_TEXTS],
        'Model/Upload/FileStorage.php' => ['$error' => self::TRANSLATED],
        'Model/Validation/RuleLibrary.php' => [
            "\$rule['label']" => self::BUILT_IN_RULES,
            "\$rule['group']" => self::BUILT_IN_RULES,
            "\$rule['message']" => self::BUILT_IN_RULES,
        ],
        'Model/Validation/SavedRuleManager.php' => ['implode(' => self::TRANSLATED],
    ];

    private const STOCK_PHRASES = [
        'Submit', 'Next', 'Back', 'Thank you, your form has been sent.', 'This form is closed.',
        'This form is not open yet.', 'Review your answers',
    ];

    public function testItKnowsThePhraseSourceOfEveryDynamicTranslationCall(): void
    {
        $found = $this->sites();
        $unknown = [];
        foreach ($found as $file => $arguments) {
            foreach ($arguments as $argument) {
                if (!isset(self::SITES[$file][$argument])) {
                    $unknown[] = $file . ': __(' . $argument . ')';
                }
            }
        }
        $stale = [];
        foreach (self::SITES as $file => $arguments) {
            foreach (array_keys($arguments) as $argument) {
                if (!in_array($argument, $found[$file] ?? [], true)) {
                    $stale[] = $file . ': __(' . $argument . ')';
                }
            }
        }

        $this->assertSame([], $unknown, 'Dynamic __() calls with no declared phrase source');
        $this->assertSame([], $stale, 'Declared dynamic __() calls that no longer exist');
    }

    public function testItHasTheBuiltInRuleAndStockTextsInTheDictionary(): void
    {
        $texts = self::STOCK_PHRASES;
        foreach ((new BuiltInRules())->all() as $rule) {
            array_push($texts, $rule['label'], $rule['group'], $rule['message']);
        }
        $dictionary = [];
        $handle = fopen(self::ROOT . '/i18n/en_US.csv', 'r');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $dictionary[(string)$row[0]] = true;
        }
        fclose($handle);

        $this->assertSame([], array_values(array_filter($texts, static fn (string $text): bool => !isset($dictionary[$text]))));
    }

    /** @return array<string, list<string>> file => first arguments that are not string literals */
    private function sites(): array
    {
        $root = (string)realpath(self::ROOT);
        $sites = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (preg_match('#^(Test|\.git|vendor)/#', $relative)
                || !in_array($file->getExtension(), ['php', 'phtml'], true)
            ) {
                continue;
            }
            preg_match_all('/(?<![\w>:$])__\(\s*(?![\'"\s])([\w$:>\[\]\'-]+\(?)/', (string)file_get_contents($file->getPathname()), $m);
            if ($m[1] !== []) {
                $sites[$relative] = array_values(array_unique($m[1]));
            }
        }
        ksort($sites);

        return $sites;
    }
}
