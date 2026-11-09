<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Outside Model/FieldType/, ask a capability interface or Pool, never a built-in type class or code.
 */
class ConcreteTypeGuardTest extends TestCase
{
    /** File => [text of a line that may name a concrete type => reason]. */
    private const ALLOWED = [
        'Model/Newsletter/SubscribeAction.php' => [
            'instanceof Newsletter' => 'subscribes to the Magento newsletter: Newsletter-specific, not consent in general',
        ],
        'Controller/Map/Search.php' => [
            "'map'" => 'the map search endpoint serves the map field type and nothing else',
        ],
    ];

    /** Classes of Model/FieldType/ that are not field types. */
    private const NOT_TYPES = ['Pool', 'SettingLabels'];

    public function testItForbidsInstanceofChecksOnConcreteFieldTypesAndTypeCodeLiteralsOutsideFieldTypes(): void
    {
        $root = dirname(__DIR__, 2);
        $classes = $this->typeClasses($root);
        $codes = $this->typeCodes($root);
        $this->assertGreaterThan(30, count($classes));
        $this->assertGreaterThan(30, count($codes));

        $instanceof = '/\binstanceof\s+(?:\\\\?Furan\\\\FormBuilder\\\\Model\\\\FieldType\\\\)?(?:' . implode('|', $classes) . ')\b/';
        // A settings-spec `type` (int, list, ...) and an HTML input type are not field type codes.
        $subject = '(?:\$(?!inputType)\w*type\w*(?:\[[^\]]*\])*|(?<!\$setting)(?<!\$definition)\[\'type\'\]|getCode\(\))';
        $literal = '\'(?:' . implode('|', array_map('preg_quote', $codes)) . ')\'';
        $comparison = '/' . $subject . '(?:\s*\?\?\s*\'\')?\)?\s*[!=]==?\s*' . $literal
            . '|' . $literal . '\s*[!=]==?\s*' . $subject . '/i';

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (!preg_match('/\.(php|phtml)$/', $relative)
                || preg_match('#^(Test|vendor|Model/FieldType)/#', $relative)
            ) {
                continue;
            }
            foreach (file($file->getPathname()) as $number => $line) {
                if ((preg_match($instanceof, $line) || preg_match($comparison, $line)) && !$this->isAllowed($relative, $line)) {
                    $offenders[] = $relative . ':' . ($number + 1);
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function testItKeepsEveryAllowedLineInUse(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (self::ALLOWED as $file => $needles) {
            $this->assertFileExists($root . '/' . $file);
            foreach ($needles as $needle => $reason) {
                $this->assertStringContainsString($needle, (string)file_get_contents($root . '/' . $file), $reason);
            }
        }
    }

    private function isAllowed(string $file, string $line): bool
    {
        foreach (array_keys(self::ALLOWED[$file] ?? []) as $needle) {
            if (str_contains($line, (string)$needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    private function typeClasses(string $root): array
    {
        $classes = [];
        foreach (glob($root . '/Model/FieldType/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            if (!in_array($name, self::NOT_TYPES, true)) {
                $classes[] = $name;
            }
        }

        return $classes;
    }

    /** @return string[] */
    private function typeCodes(string $root): array
    {
        $xml = simplexml_load_file($root . '/etc/di.xml');
        $codes = [];
        foreach ($xml->xpath("//type[@name='Furan\\FormBuilder\\Model\\FieldType\\Pool']/arguments/argument[@name='types']/item") as $item) {
            $codes[] = (string)$item['name'];
        }

        return $codes;
    }
}
