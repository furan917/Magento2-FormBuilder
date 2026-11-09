<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Access;

use Furan\FormBuilder\Model\Access\FormAccess;
use PHPUnit\Framework\TestCase;

class FormAccessTest extends TestCase
{
    public function testItAllowsEveryoneWhenNoAccessRuleIsSet(): void
    {
        $access = new FormAccess();

        $this->assertNull($access->check([], false, 0));
        $this->assertNull($access->check(['access_login_required' => false, 'access_groups' => []], true, 5));
    }

    public function testItRejectsAGuestWhenLoginIsRequired(): void
    {
        $access = new FormAccess();

        $this->assertSame('login', $access->check(['access_login_required' => true], false, 0));
        $this->assertNull($access->check(['access_login_required' => true], true, 1));
    }

    public function testItRejectsACustomerWhoseGroupIsNotAllowed(): void
    {
        $access = new FormAccess();
        $settings = ['access_groups' => [1, 2]];

        $this->assertSame('group', $access->check($settings, true, 3));
        $this->assertNull($access->check($settings, true, 2));
    }

    public function testItAsksAGuestToLogInWhenTheAllowedGroupsExcludeNotLoggedIn(): void
    {
        $access = new FormAccess();

        $this->assertSame('login', $access->check(['access_groups' => [1]], false, 0));
        $this->assertNull($access->check(['access_groups' => [0, 1]], false, 0));
        $this->assertSame('group', $access->check(['access_groups' => [0]], true, 1));
    }
}
