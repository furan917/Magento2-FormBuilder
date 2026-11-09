<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The store of a request is chosen by core (URL code, request parameter or cookie). Module code never reads
 * either source itself: the guard for a form is its store assignment, checked for the resolved store.
 */
class StoreParamGuardTest extends TestCase
{
    private const DIRECTORIES = ['Api', 'Block', 'Console', 'Controller', 'Model', 'Observer', 'Plugin', 'Ui', 'ViewModel'];
    private const FORBIDDEN = ['___store', 'StoreManagerInterface::PARAM_NAME', 'StoreCookieManager'];

    public function testNoModuleClassReadsTheStoreRequestParameterOrTheStoreCookie(): void
    {
        $root = dirname(__DIR__, 2);
        $scanned = 0;
        $offenders = [];
        foreach (self::DIRECTORIES as $directory) {
            if (!is_dir($root . '/' . $directory)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $scanned++;
                $source = (string)file_get_contents($file->getPathname());
                foreach (self::FORBIDDEN as $token) {
                    if (str_contains($source, $token)) {
                        $offenders[] = substr($file->getPathname(), strlen($root) + 1) . ' (' . $token . ')';
                    }
                }
            }
        }

        $this->assertGreaterThan(100, $scanned);
        $this->assertSame([], $offenders);
    }
}
