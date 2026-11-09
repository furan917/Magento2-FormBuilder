<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Core di.xml gives RemoteAddress alternativeHeaders=HTTP_X_FORWARDED_FOR, so any visitor can pick their own IP.
 * Client addresses must come from Model\Http\ClientIp, which only believes configured proxies.
 */
class ClientIpGuardTest extends TestCase
{
    public function testNoModuleClassReadsTheClientAddressFromCoreRemoteAddress(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (!str_ends_with($relative, '.php') || str_starts_with($relative, 'Test/')
                || $relative === 'Model/Http/ClientIp.php'
            ) {
                continue;
            }
            if (str_contains((string)file_get_contents($file->getPathname()), 'PhpEnvironment\\RemoteAddress')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders);
    }
}
