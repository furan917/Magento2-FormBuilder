<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Account;

use Furan\FormBuilder\Controller\Account\Login;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Url\EncoderInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class LoginTest extends TestCase
{
    private const FORM = 'https://shop.test/forms/contact';

    private Session&Stub $session;
    private Redirect&Stub $redirect;
    private RedirectValidator&Stub $validator;

    protected function setUp(): void
    {
        $this->session = $this->createStub(Session::class);
        $this->redirect = $this->configureRedirect($this->createStub(Redirect::class));
        $this->validator = $this->createStub(RedirectValidator::class);
        $this->validator->method('isAllowed')->willReturnCallback(
            static fn(string $u): bool => str_starts_with($u, 'https://shop.test/')
        );
    }

    private function controller(array $params): Login
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn(string $k, $d = null) => $params[$k] ?? $d);
        $factory = $this->createStub(RedirectFactory::class);
        $factory->method('create')->willReturn($this->redirect);
        $encoder = $this->createStub(EncoderInterface::class);
        $encoder->method('encode')->willReturnCallback(static fn(string $u): string => 'enc:' . $u);

        return new Login($request, $factory, $this->session, $this->validator, $encoder);
    }

    /**
     * @template T of Redirect&Stub
     * @param T $redirect
     * @return T
     */
    private function configureRedirect(Redirect&Stub $redirect): Redirect&Stub
    {
        $redirect->method('setPath')->willReturnSelf();
        $redirect->method('setUrl')->willReturnSelf();
        return $redirect;
    }

    public function testItIsAGetActionThatIsNotAnAccountAction(): void
    {
        $this->assertInstanceOf(HttpGetActionInterface::class, $this->controller([]));
        $this->assertNotInstanceOf(\Magento\Customer\Controller\AccountInterface::class, $this->controller([]));
    }

    public function testItSetsTheBeforeAuthUrlOnlyForAnOwnOriginReturnUrl(): void
    {
        $redirect = $this->redirect = $this->configureRedirect($this->createMock(Redirect::class));
        $session = $this->session = $this->createMock(Session::class);
        $session->expects($this->once())->method('setBeforeAuthUrl')->with(self::FORM);
        $redirect->expects($this->once())->method('setPath')
            ->with('customer/account/login', ['_scope_to_url' => true, 'referer' => 'enc:' . self::FORM]);

        $this->controller(['return' => self::FORM])->execute();
    }

    public function testItDoesNotSetTheBeforeAuthUrlForAForeignOrMissingReturnUrl(): void
    {
        $redirect = $this->redirect = $this->configureRedirect($this->createMock(Redirect::class));
        $session = $this->session = $this->createMock(Session::class);
        $session->expects($this->never())->method('setBeforeAuthUrl');
        $redirect->expects($this->exactly(2))->method('setPath')->with('customer/account/login', ['_scope_to_url' => true]);

        $this->controller(['return' => 'https://evil.test/x'])->execute();
        $this->controller([])->execute();
    }

    public function testItRejectsBackslashJavascriptAndProtocolRelativeReturnUrls(): void
    {
        $session = $this->session = $this->createMock(Session::class);
        $session->expects($this->never())->method('setBeforeAuthUrl');
        foreach (['javascript:alert(1)', '/\\evil.com', 'https:\\\\evil.com', '//evil.com', '/forms/contact', ['a']] as $bad) {
            $this->controller(['return' => $bad])->execute();
        }
    }

    public function testItKeepsTheStoreMarkerOnTheLoginRedirect(): void
    {
        $redirect = $this->redirect = $this->configureRedirect($this->createMock(Redirect::class));
        $redirect->expects($this->once())->method('setPath')->with('customer/account/login', ['_scope_to_url' => true]);

        $this->controller([])->execute();
    }

    public function testItRedirectsToTheCustomerLoginPage(): void
    {
        $redirect = $this->redirect = $this->configureRedirect($this->createMock(Redirect::class));
        $redirect->expects($this->once())->method('setPath')->with('customer/account/login', ['_scope_to_url' => true]);

        $this->controller([])->execute();
    }

    public function testItRedirectsToTheRegistrationPageWhenRegisterIsRequested(): void
    {
        $redirect = $this->redirect = $this->configureRedirect($this->createMock(Redirect::class));
        $session = $this->session = $this->createMock(Session::class);
        $session->expects($this->once())->method('setBeforeAuthUrl')->with(self::FORM);
        $redirect->expects($this->once())->method('setPath')
            ->with('customer/account/create', ['_scope_to_url' => true, 'referer' => 'enc:' . self::FORM]);

        $this->controller(['return' => self::FORM, 'register' => '1'])->execute();
    }

    public function testItSendsAnAlreadyLoggedInVisitorStraightToTheReturnUrl(): void
    {
        $redirect = $this->redirect = $this->configureRedirect($this->createMock(Redirect::class));
        $session = $this->session = $this->createMock(Session::class);
        $this->session->method('isLoggedIn')->willReturn(true);
        $session->expects($this->never())->method('setBeforeAuthUrl');
        $redirect->expects($this->once())->method('setUrl')->with(self::FORM);
        $redirect->expects($this->never())->method('setPath');

        $this->controller(['return' => self::FORM])->execute();
    }
}
