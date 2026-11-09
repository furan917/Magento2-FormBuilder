<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use PHPUnit\Framework\TestCase;

class ControllerImportGuardTest extends TestCase
{
    public function testItImportsNoControllerClassFromABlockOrModel(): void
    {
        $root = dirname(__DIR__, 2);
        $scanned = 0;
        $offenders = [];
        foreach (['Block', 'Model', 'Ui', 'Api'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$directory", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $scanned++;
                if (preg_match('/^\s*use\s+Furan\\\\FormBuilder\\\\Controller\\\\|(?<![\w\\\\])\\\\?Furan\\\\FormBuilder\\\\Controller\\\\\w+::/m', (string)file_get_contents($file->getPathname())) === 1) {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        $this->assertGreaterThan(100, $scanned);
        $this->assertSame([], $offenders);
    }
}
