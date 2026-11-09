<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use PHPUnit\Framework\TestCase;

/** In adminhtml the plain UrlFactory builds backend URLs, so every class that links to the storefront gets the frontend one. */
class FrontendUrlWiringTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private const FACTORY = 'Furan\\FormBuilder\\Model\\Url\\FrontendUrlFactory';

    public function testTheFrontendUrlFactoryBuildsStorefrontUrls(): void
    {
        $di = simplexml_load_file(self::ROOT . '/etc/di.xml');
        $type = $di->xpath('//virtualType[@name="' . self::FACTORY . '"]')[0];

        $this->assertSame('Magento\\Framework\\UrlFactory', (string)$type['type']);
        $this->assertSame('Magento\\Framework\\Url', (string)$type->xpath('arguments/argument[@name="instanceName"]')[0]);
    }

    public function testEveryClassThatTakesAUrlFactoryGetsTheFrontendOne(): void
    {
        $di = simplexml_load_file(self::ROOT . '/etc/di.xml');
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = substr((string)$file, strlen(self::ROOT) + 1);
            if (!str_ends_with($path, '.php') || preg_match('#^(Test|vendor)/#', $path)) {
                continue;
            }
            $source = (string)file_get_contents((string)$file);
            if (!str_contains($source, 'use Magento\\Framework\\UrlFactory;')
                || !preg_match_all('/\bUrlFactory \$(\w+)/', $source, $params)
            ) {
                continue;
            }
            $class = 'Furan\\FormBuilder\\' . str_replace('/', '\\', substr($path, 0, -4));
            foreach ($params[1] as $param) {
                $found[] = $class;
                $argument = $di->xpath('//type[@name="' . $class . '"]/arguments/argument[@name="' . $param . '"]');
                $this->assertSame(self::FACTORY, isset($argument[0]) ? (string)$argument[0] : null, $class . ' $' . $param);
            }
        }
        $this->assertContains(\Furan\FormBuilder\Model\Admin\PreviewLink::class, $found);
        $this->assertContains(\Furan\FormBuilder\Model\Seo\FormUrls::class, $found);
    }
}
