<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Customer;

use Furan\FormBuilder\Model\Customer\Impersonation;
use Magento\Customer\Model\Session;
use PHPUnit\Framework\TestCase;

class ImpersonationTest extends TestCase
{
    private function impersonation(mixed $flag): Impersonation
    {
        $session = $this->createMock(Session::class);
        $session->method('getData')->with('logged_as_customer_admind_id')->willReturn($flag);

        return new Impersonation($session);
    }

    public function testItDetectsAnAdminLoggedInAsTheCustomerFromTheCustomerSessionFlag(): void
    {
        $this->assertTrue($this->impersonation(3)->isActive());
        $this->assertTrue($this->impersonation('3')->isActive());
    }

    public function testItReportsNoImpersonationWhenTheSessionFlagIsAbsentOrZero(): void
    {
        $this->assertFalse($this->impersonation(null)->isActive());
        $this->assertFalse($this->impersonation(0)->isActive());
        $this->assertFalse($this->impersonation('0')->isActive());
    }

    public function testItReferencesNoLoginAsCustomerClassSoCompilationNeverDependsOnTheModule(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 4) . '/Model/Customer/Impersonation.php');

        $this->assertStringNotContainsString('LoginAsCustomer', $source);
    }
}
