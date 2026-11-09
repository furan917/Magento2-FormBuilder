<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Developer mode validates config, layout and UI component XML against Magento's XSDs; default and production do not.
 */
class XmlSchemaTest extends TestCase
{
    private const MODULE = __DIR__ . '/../..';
    private const VENDOR = __DIR__ . '/../../../../../../vendor/mage-os';

    /** @return array<string, array{string}> */
    public static function configFiles(): array
    {
        $files = [];
        $patterns = ['/etc/{,*/}*.xml', '/view/*/layout/*.xml', '/view/*/ui_component/*.xml', '/view/*/page_layout/*.xml'];
        foreach (array_merge(...array_map(static fn (string $p): array => glob(self::MODULE . $p, GLOB_BRACE) ?: [], $patterns)) as $file) {
            $files[substr($file, strlen(self::MODULE) + 1)] = [$file];
        }
        return $files;
    }

    #[DataProvider('configFiles')]
    public function testEveryConfigFileValidatesAgainstItsMagentoSchema(string $file): void
    {
        $doc = new \DOMDocument();
        $doc->load($file);
        $location = $doc->documentElement?->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'noNamespaceSchemaLocation') ?? '';
        $xsd = $this->resolve($location);
        if ($xsd === null) {
            $this->markTestSkipped('Schema not installed: ' . $location);
        }

        libxml_use_internal_errors(true);
        libxml_set_external_entity_loader(fn (?string $public, string $system): ?string => $this->resolve($system) ?? $system);
        try {
            $valid = $doc->schemaValidate($xsd);
            $errors = array_map(static fn (\LibXMLError $e): string => trim($e->message) . ' (line ' . $e->line . ')', libxml_get_errors());
        } finally {
            libxml_clear_errors();
            libxml_set_external_entity_loader(null);
            libxml_use_internal_errors(false);
        }

        $this->assertTrue($valid, implode("\n", $errors));
    }

    private function resolve(string $urn): ?string
    {
        if (preg_match('/^urn:magento:(framework[a-z-]*):(.+)$/', $urn, $m) === 1) {
            $path = self::VENDOR . '/' . $m[1] . '/' . $m[2];
        } elseif (preg_match('/^urn:magento:module:Magento_([A-Za-z]+):(.+)$/', $urn, $m) === 1) {
            $package = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', $m[1]));
            $path = self::VENDOR . '/module-' . $package . '/' . $m[2];
        } else {
            return null;
        }
        return is_file($path) ? (string)realpath($path) : null;
    }
}
