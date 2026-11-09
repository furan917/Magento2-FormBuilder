<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use PHPUnit\Framework\TestCase;

class RawSqlBindTest extends TestCase
{
    // Raw fetch/query binds do not expand arrays: "IN (?)" with an array silently matches nothing.
    public function testItNeverBindsAnArrayToARawInPlaceholder(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() !== 'php' || str_contains($path, '/Test/')) {
                continue;
            }
            foreach (file($path) as $n => $line) {
                if (str_contains($line, 'IN (?)')
                    && !preg_match('/->(where|orWhere|quoteInto)\(|\(\?\)\'\s*=>/', $line)
                ) {
                    $offenders[] = substr($path, strlen($root) + 1) . ':' . ($n + 1);
                }
            }
        }
        $this->assertSame([], $offenders);
    }
}
