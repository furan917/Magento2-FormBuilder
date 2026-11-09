<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Config;

use PHPUnit\Framework\TestCase;

class ModuleDependenciesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../';

    /** package => module, null for a framework package */
    private const REQUIRED = [
        'magento/module-catalog' => 'Magento_Catalog',
        'magento/module-config' => 'Magento_Config',
        'magento/module-cron' => 'Magento_Cron',
        'magento/module-sitemap' => 'Magento_Sitemap',
        'magento/framework-message-queue' => null,
    ];

    public function testItRequiresEveryModuleItsCodeAndConfigUse(): void
    {
        $composer = json_decode((string)file_get_contents(self::ROOT . 'composer.json'), true);
        $module = simplexml_load_file(self::ROOT . 'etc/module.xml');
        $this->assertNotFalse($module);

        foreach (self::REQUIRED as $package => $name) {
            $this->assertArrayHasKey($package, $composer['require'], $package);
            if ($name !== null) {
                $this->assertCount(1, $module->xpath('/config/module/sequence/module[@name="' . $name . '"]'), $name);
            }
        }
    }

    public function testItOnlySuggestsTheModulesItWorksWithout(): void
    {
        $composer = json_decode((string)file_get_contents(self::ROOT . 'composer.json'), true);

        foreach (['magento/module-user', 'magento/module-sales', 'magento/module-newsletter'] as $package) {
            $this->assertArrayHasKey($package, $composer['suggest'], $package);
            $this->assertArrayNotHasKey($package, $composer['require'], $package);
        }
    }
}
