<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use Furan\FormBuilder\Model\Settings\FormSettings;
use PHPUnit\Framework\TestCase;

class UnreferencedClassesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private const FOLDERS = ['Api', 'Block', 'Console', 'Model', 'Observer', 'Plugin', 'Ui', 'ViewModel'];

    /** Extension points nothing in the module calls on purpose; each needs a reason. */
    private const ALLOWLIST = [
        FormSettings::class => 'documented reader for sibling modules that register form settings contributors',
    ];

    public function testItReferencesEveryNonApiClassSomewhereInTheModule(): void
    {
        $sources = $this->sources();
        $unreferenced = [];
        foreach ($this->classFiles() as $path => $fqcn) {
            if (isset(self::ALLOWLIST[$fqcn])) {
                continue;
            }
            if (!$this->isReferenced($fqcn, $path, $sources)) {
                $unreferenced[] = $fqcn;
            }
        }

        $this->assertSame([], $unreferenced, 'Classes that nothing outside their own file references');
    }

    /**
     * @param array<string, string> $sources
     */
    private function isReferenced(string $fqcn, string $ownPath, array $sources): bool
    {
        $short = substr($fqcn, (int)strrpos($fqcn, '\\') + 1);
        $namespace = substr($fqcn, 0, (int)strrpos($fqcn, '\\'));
        $patterns = [
            '/' . preg_quote($fqcn, '/') . '(?:Factory)?\b/',
            '/' . preg_quote(str_replace('\\', '\\\\', $fqcn), '/') . '(?:Factory)?\b/',
        ];
        $sameNamespace = '/\bnamespace\s+' . preg_quote($namespace, '/') . ';/';
        $shortName = '/\b' . preg_quote($short, '/') . '(?:Factory)?\b/';
        foreach ($sources as $path => $code) {
            if ($path === $ownPath) {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    return true;
                }
            }
            if (preg_match($sameNamespace, $code) === 1 && preg_match($shortName, $code) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string> path => fully qualified class name
     */
    private function classFiles(): array
    {
        $root = (string)realpath(self::ROOT);
        $classes = [];
        foreach (self::FOLDERS as $folder) {
            foreach ($this->phpFiles($root . '/' . $folder) as $path) {
                $relative = substr($path, strlen($root) + 1, -4);
                $classes[$path] = 'Furan\\FormBuilder\\' . str_replace('/', '\\', $relative);
            }
        }

        return $classes;
    }

    /**
     * Every non-test source a class can be referenced from: PHP, templates, XML and JSON configuration.
     *
     * @return array<string, string> path => contents
     */
    private function sources(): array
    {
        $root = (string)realpath(self::ROOT);
        $sources = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (preg_match('#^(Test|\.git|docs|i18n)/#', $relative)
                || !in_array($file->getExtension(), ['php', 'phtml', 'xml', 'json'], true)
            ) {
                continue;
            }
            $sources[$file->getPathname()] = (string)file_get_contents($file->getPathname());
        }

        return $sources;
    }

    /**
     * @return string[]
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
