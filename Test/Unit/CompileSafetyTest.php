<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use PHPUnit\Framework\TestCase;

class CompileSafetyTest extends TestCase
{
    // Magento's interceptor generator cannot render `= new X()` parameter defaults.
    public function testItUsesNoNewExpressionsAsParameterDefaults(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() !== 'php' || str_contains($path, '/Test/')) {
                continue;
            }
            if (preg_match('/\$\w+\s*=\s*new\s+[\\\\\w]+\([^;{]*\)\s*[,)]/', (string)file_get_contents($path))) {
                $offenders[] = substr($path, strlen($root) + 1);
            }
        }
        $this->assertSame([], $offenders);
    }
}
