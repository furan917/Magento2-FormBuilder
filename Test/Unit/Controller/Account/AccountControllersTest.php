<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Account;

use Magento\Customer\Controller\AccountInterface;
use PHPUnit\Framework\TestCase;

class AccountControllersTest extends TestCase
{
    // Magento's customer account plugin returns null for guests (after redirecting to login); a declared return type turns that into a 500.
    public function testItDeclaresNoReturnTypeOnAccountControllersSoTheLoginRedirectWorksForGuests(): void
    {
        $typed = [];
        foreach (glob(dirname(__DIR__, 4) . '/Controller/Account/*.php') as $file) {
            $class = 'Furan\\FormBuilder\\Controller\\Account\\' . basename($file, '.php');
            $reflection = new \ReflectionClass($class);
            if ($reflection->implementsInterface(AccountInterface::class)
                && $reflection->getMethod('execute')->hasReturnType()
            ) {
                $typed[] = $reflection->getShortName();
            }
        }
        $this->assertSame([], $typed);
    }
}
