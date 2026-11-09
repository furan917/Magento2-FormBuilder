<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use Furan\FormBuilder\Model\Admin\BuilderSharedConfig;
use Furan\FormBuilder\Model\Schema\ContentTypes;
use PHPUnit\Framework\TestCase;

class I18nUnusedPhrasesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** Files whose string literals are translated later through __($variable); each needs a reason. */
    private const DYNAMIC_SOURCES = [
        'Model/Validation/BuiltInRules.php' => 'RuleLibrary translates label, group and message with __($rule[...])',
        'Model/I18n/Translator.php' => 'stock form texts that are still the default follow the store view language pack',
    ];

    public function testItHasNoUnusedEnUsPhrases(): void
    {
        $used = array_flip($this->usedPhrases());
        $unused = [];
        foreach ($this->dictionary() as $phrase) {
            if (!isset($used[$phrase])) {
                $unused[] = $phrase;
            }
        }

        $this->assertSame([], $unused, 'en_US phrases that no source translates');
    }

    /**
     * @return string[]
     */
    private function dictionary(): array
    {
        $phrases = [];
        $handle = fopen(self::ROOT . '/i18n/en_US.csv', 'r');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($row !== [null]) {
                $phrases[] = $row[0];
            }
        }
        fclose($handle);

        return $phrases;
    }

    /**
     * @return string[]
     */
    private function usedPhrases(): array
    {
        $used = [];
        $root = (string)realpath(self::ROOT);
        foreach ($this->files() as $path => $extension) {
            $code = (string)file_get_contents($path);
            $found = match ($extension) {
                'php', 'phtml' => $this->phpPhrases($code),
                'html' => $this->emailPhrases($code),
                default => $this->xmlPhrases($code),
            };
            array_push($used, ...$found);
            if (isset(self::DYNAMIC_SOURCES[substr($path, strlen($root) + 1)])) {
                array_push($used, ...$this->stringLiterals($code));
            }
        }

        return array_merge($used, array_values(ContentTypes::labels()), array_values($this->widthLabels()));
    }

    /** @return array<string, string> */
    private function widthLabels(): array
    {
        return (new \ReflectionClassConstant(BuilderSharedConfig::class, 'WIDTHS'))->getValue();
    }

    /**
     * @return array<string, string> path => extension
     */
    private function files(): array
    {
        $root = (string)realpath(self::ROOT);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $extension = $file->getExtension();
            if (preg_match('#^(Test|\.git|docs|i18n)/#', $relative)) {
                continue;
            }
            $isEmail = $extension === 'html' && str_starts_with($relative, 'view/frontend/email/');
            $isXml = $extension === 'xml' && preg_match('#^(etc|view)/#', $relative) === 1;
            if (in_array($extension, ['php', 'phtml'], true) || $isEmail || $isXml) {
                $files[$file->getPathname()] = $extension;
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @return string[]
     */
    private function phpPhrases(string $code): array
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

    /**
     * @return string[]
     */
    private function stringLiterals(string $code): array
    {
        $literals = [];
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literals[] = stripcslashes(substr($token[1], 1, -1));
            }
        }

        return $literals;
    }

    /**
     * @return string[]
     */
    private function emailPhrases(string $html): array
    {
        preg_match_all('/\{\{trans\s+(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\')/s', $html, $matches);
        $phrases = [];
        foreach ([1, 2] as $group) {
            foreach ($matches[$group] as $phrase) {
                if ($phrase !== '') {
                    $phrases[] = stripslashes($phrase);
                }
            }
        }

        return $phrases;
    }

    /**
     * Element texts and attribute values: system.xml, menu, acl, widget, layout and ui_component files translate them.
     *
     * @return string[]
     */
    private function xmlPhrases(string $xml): array
    {
        $document = simplexml_load_string($xml);
        $phrases = [];
        foreach ($document->xpath('//*') ?: [] as $node) {
            $text = trim((string)$node);
            if ($text !== '') {
                $phrases[] = $text;
            }
            foreach ($node->attributes() ?? [] as $value) {
                $phrases[] = (string)$value;
            }
        }

        return $phrases;
    }
}
