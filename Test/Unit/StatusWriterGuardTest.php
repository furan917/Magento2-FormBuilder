<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A status change and its webhook deliveries commit together, so every site that changes a submission's
 * status goes through Model\Submission\StatusWriter.
 */
class StatusWriterGuardTest extends TestCase
{
    private const ALLOWED = [
        'Model/Submission/StatusWriter.php',
        'Model/Event/SubmissionEvents.php',
        'Model/Thread/ThreadAccess.php',
    ];

    public function testItUsesTheSameStatusWriterForEveryStatusChange(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (!str_ends_with($relative, '.php') || str_starts_with($relative, 'Test/') || in_array($relative, self::ALLOWED, true)) {
                continue;
            }
            $code = (string)file_get_contents($file->getPathname());
            if (preg_match('/->statusChanged\(|::statusBind\(/', $code) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders);
    }
}
