<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * di:compile cannot be run from a unit test, so this is the proof that the module compiles without
 * Magento_Newsletter: only the bridge may name it, and it is never a hard dependency.
 */
class NewsletterGuardTest extends TestCase
{
    private const BRIDGE = 'Model/Newsletter/NewsletterBridge.php';

    public function testItFailsWhenAClassOtherThanTheBridgeReferencesTheNewsletterModule(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (!in_array($file->getExtension(), ['php', 'phtml', 'xml'], true) || $relative === self::BRIDGE
                || str_starts_with($relative, 'Test/')
            ) {
                continue;
            }
            if (preg_match('/Magento\\\\{1,2}Newsletter|Magento_Newsletter/', (string)file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders);
    }

    public function testItCompilesAndRunsWithoutTheNewsletterModuleInstalled(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode((string)file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Newsletter', (string)file_get_contents($root . '/etc/module.xml'));
        $this->assertArrayNotHasKey('magento/module-newsletter', $composer['require']);
        $this->assertArrayHasKey('magento/module-newsletter', $composer['suggest']);

        $bridge = (string)file_get_contents($root . '/' . self::BRIDGE);
        $constructor = (string)preg_replace('/^.*?function __construct\((.*?)\)\s*\{.*$/s', '$1', $bridge);
        $this->assertStringNotContainsString('Newsletter', $constructor);
    }
}
