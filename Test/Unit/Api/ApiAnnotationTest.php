<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Api;

use PHPUnit\Framework\TestCase;

class ApiAnnotationTest extends TestCase
{
    public function testItMarksEveryInterfaceInApiAsApi(): void
    {
        $root = dirname(__DIR__, 3) . '/Api';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $missing = [];
        $count = 0;
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'Furan\\FormBuilder\\Api\\' . str_replace('/', '\\', $relative);
            $count++;
            $doc = (new \ReflectionClass($class))->getDocComment();
            if ($doc === false || !preg_match('/@api\b/', $doc)) {
                $missing[] = $class;
            }
        }
        $this->assertGreaterThan(20, $count);
        $this->assertSame([], $missing, 'Classes in Api without @api');
    }
}
