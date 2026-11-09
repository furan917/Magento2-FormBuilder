<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Plugin\Admin;

use Furan\FormBuilder\Controller\Adminhtml\Submission\Open;
use Furan\FormBuilder\Plugin\Admin\SubmissionLinkLogin;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Backend\Model\Session;
use Magento\Backend\Model\Url;
use Magento\Framework\App\RequestInterface;
use PHPUnit\Framework\TestCase;

class SubmissionLinkLoginTest extends TestCase
{
    private bool $loggedIn = false;
    private bool $secretKeys = true;
    private Session $session;

    private function plugin(): SubmissionLinkLogin
    {
        $auth = $this->createStub(AuthSession::class);
        $auth->method('isLoggedIn')->willReturnCallback(fn (): bool => $this->loggedIn);
        $this->session ??= new class extends Session {
            /** @var array<string, mixed> */
            public array $store = [];

            public function __construct()
            {
            }

            public function setData($key, $value = null)
            {
                $this->store[$key] = $value;
                return $this;
            }

            public function getData($key = '', $clear = false)
            {
                $value = $this->store[$key] ?? null;
                if ($clear) {
                    unset($this->store[$key]);
                }
                return $value;
            }
        };
        $url = $this->createStub(\Magento\Backend\Model\UrlInterface::class);
        $url->method('useSecretKey')->willReturnCallback(fn (): bool => $this->secretKeys);
        return new SubmissionLinkLogin($auth, $this->session, $url);
    }

    private function open(mixed $id): void
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key) => $key === 'id' ? $id : null);
        $this->plugin()->beforeDispatch($this->createStub(Open::class), $request);
    }

    private function startup(): string
    {
        return $this->plugin()->afterGetStartupPageUrl($this->createStub(Url::class), 'admin/dashboard');
    }

    public function testALoggedOutAdminLandsOnTheLinkedSubmissionAfterLoggingIn(): void
    {
        $this->open('34864');
        $this->assertSame('admin/dashboard', $this->startup(), 'not consumed before the login');

        $this->loggedIn = true;
        $this->assertSame('furanform/submission/view/id/34864', $this->startup());
        $this->assertSame('admin/dashboard', $this->startup(), 'used once');
    }

    public function testNothingIsRememberedWhenSecretKeysAreOffBecauseCoreReturnsToTheLinkItself(): void
    {
        $this->secretKeys = false;
        $this->open('34864');
        $this->loggedIn = true;

        $this->assertSame('admin/dashboard', $this->startup());
    }

    public function testALoggedInAdminIsNotRemembered(): void
    {
        $this->loggedIn = true;
        $this->open('34864');

        $this->assertSame('admin/dashboard', $this->startup());
    }

    public function testAnUnusableIdIsNotRemembered(): void
    {
        foreach (['', 'abc', '0', '-3', '34864abc', null] as $id) {
            $this->open($id);
        }
        $this->loggedIn = true;

        $this->assertSame('admin/dashboard', $this->startup());
    }
}
