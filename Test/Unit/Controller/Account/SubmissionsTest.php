<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Account;

use Furan\FormBuilder\Controller\Account\Submission;
use Furan\FormBuilder\Controller\Account\Submissions;
use Magento\Customer\Controller\AccountInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\TestCase;

class SubmissionsTest extends TestCase
{
    /** Anchored allowedActions of Magento\Customer\Controller\Plugin\Account (module-customer etc/frontend/di.xml). */
    private const PLUGIN_ALLOWED = '/^(create|login|logoutsuccess|forgotpassword|forgotpasswordpost|resetpassword|resetpasswordpost|confirm|confirmation|createpassword|createpost|loginpost)$/i';

    public function testItRedirectsGuestsToTheLoginPage(): void
    {
        foreach ([Submissions::class, Submission::class] as $class) {
            $interfaces = class_implements($class);
            $this->assertContains(AccountInterface::class, $interfaces);
            $this->assertContains(HttpGetActionInterface::class, $interfaces);
        }
        foreach (['submissions', 'submission', 'edit', 'delete', 'reply', 'export', 'deleteall', 'claim'] as $action) {
            $this->assertDoesNotMatchRegularExpression(self::PLUGIN_ALLOWED, $action);
        }
        $this->assertSame('Submissions', (new \ReflectionClass(Submissions::class))->getShortName());
        $this->assertSame('Submission', (new \ReflectionClass(Submission::class))->getShortName());
    }

    public function testItReturns404WhenTheSessionHasNoCustomerId(): void
    {
        $forward = $this->createMock(Forward::class);
        $forward->expects($this->once())->method('forward')->with('noroute')->willReturnSelf();
        $forwardFactory = $this->createStub(ForwardFactory::class);
        $forwardFactory->method('create')->willReturn($forward);
        $pageFactory = $this->createMock(PageFactory::class);
        $pageFactory->expects($this->never())->method('create');
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturn(null);

        $this->assertSame($forward, (new Submissions($pageFactory, $forwardFactory, $session))->execute());
    }

    public function testItRendersTheListPageForALoggedInCustomer(): void
    {
        $page = $this->createStub(Page::class);
        $pageFactory = $this->createStub(PageFactory::class);
        $pageFactory->method('create')->willReturn($page);
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturn('7');

        $this->assertSame(
            $page,
            (new Submissions($pageFactory, $this->createStub(ForwardFactory::class), $session))->execute()
        );
    }
}
