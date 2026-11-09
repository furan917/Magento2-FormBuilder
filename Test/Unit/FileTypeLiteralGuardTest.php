<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Whether an answer is a file is asked of the field type (FieldType\Pool::isFile), never decided by the code "file".
 */
class FileTypeLiteralGuardTest extends TestCase
{
    private const PATTERNS = [
        '/[!=]==?\s*[\'"]file[\'"]/',
        '/[\'"]file[\'"]\s*[!=]==?/',
        '/in_array\([^;]*[\'"]file[\'"]/',
        '/\bcase\s+[\'"]file[\'"]/',
        '/\bconst\s+\w+\s*=\s*\[[^\]]*[\'"]file[\'"]/',
    ];

    private const ALLOWED = ['Model/FieldType/File.php'];

    public function testItLeavesNoLiteralFileTypeComparisonOutsideTheFileFieldTypeClass(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (!preg_match('/\.(php|phtml)$/', $relative) || preg_match('#^(Test|vendor)/#', $relative)
                || in_array($relative, self::ALLOWED, true)
            ) {
                continue;
            }
            foreach (file($file->getPathname()) as $number => $line) {
                foreach (self::PATTERNS as $pattern) {
                    if (preg_match($pattern, $line)) {
                        $offenders[] = $relative . ':' . ($number + 1);
                        break;
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
