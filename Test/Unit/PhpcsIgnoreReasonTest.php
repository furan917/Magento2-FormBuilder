<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use PHPUnit\Framework\TestCase;

class PhpcsIgnoreReasonTest extends TestCase
{
    public function testItHasNoPhpcsIgnoreOrDisableWithoutAReason(): void
    {
        $root = dirname(__DIR__, 2);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $missing = [];
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (!preg_match('/\.(php|phtml)$/', $path) || str_contains($path, '/vendor/') || $path === __FILE__) {
                continue;
            }
            foreach (file($path) as $number => $line) {
                if (preg_match('/phpcs:(ignore|disable)\b(?!.*\s--\s+\S)/', $line)) {
                    $missing[] = substr($path, strlen($root) + 1) . ':' . ($number + 1);
                }
            }
        }

        $this->assertSame([], $missing, 'phpcs ignore or disable without " -- reason"');
    }
}
