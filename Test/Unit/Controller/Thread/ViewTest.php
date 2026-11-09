<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Thread;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Controller\Thread\View;
use Furan\FormBuilder\Model\Thread\CurrentThread;
use Furan\FormBuilder\Model\Thread\PrivateResponse;
use Furan\FormBuilder\Model\Thread\ThreadAccess;
use Furan\FormBuilder\Model\Thread\ThreadGrants;
use Furan\FormBuilder\Model\Thread\ThreadLimits;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ViewTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private array $params = [];
    private ?array $redeemed = null;
    private ?array $loaded = null;
    private bool $granted = false;
    private bool $allowAttempt = true;
    private int $sessionCustomerId = 0;
    private ?int $ownerId = null;
    private array $headers = [];
    private array $paths = [];
    private ?int $code = null;
    private CurrentThread $current;
    private ThreadAccess&Stub $access;
    private ThreadGrants&Stub $grants;
    private ThreadLimits&Stub $limits;

    private function controller(): View
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(fn (string $key) => $this->params[$key] ?? null);
        $record = function (string $name, string $value): void {
            $this->headers[$name] = $value;
        };
        $page = $this->createStub(Page::class);
        $page->method('setHeader')->willReturnCallback(function (string $n, string $v) use ($record, $page) {
            $record($n, $v);
            return $page;
        });
        $page->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($page) {
            $this->code = $code;
            return $page;
        });
        $page->method('getConfig')->willReturn($this->createStub(PageConfig::class));
        $pages = $this->createStub(PageFactory::class);
        $pages->method('create')->willReturn($page);
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setHeader')->willReturnCallback(function (string $n, string $v) use ($record, $redirect) {
            $record($n, $v);
            return $redirect;
        });
        $redirect->method('setPath')->willReturnCallback(function (string $path, array $params = []) use ($redirect) {
            $this->paths[] = [$path, $params];
            return $redirect;
        });
        $redirects = $this->createStub(RedirectFactory::class);
        $redirects->method('create')->willReturn($redirect);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        $this->access ??= $this->createStub(ThreadAccess::class);
        $this->access->method('redeem')->willReturnCallback(fn () => $this->redeemed);
        $this->access->method('load')->willReturnCallback(fn (int $id) => $id > 0 ? $this->loaded : null);
        $this->grants ??= $this->createStub(ThreadGrants::class);
        $this->grants->method('allows')->willReturnCallback(fn () => $this->granted);
        $this->limits ??= $this->createStub(ThreadLimits::class);
        $this->limits->method('allowTokenAttempt')->willReturnCallback(fn () => $this->allowAttempt);
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturnCallback(fn () => $this->sessionCustomerId ?: null);
        $this->current = new CurrentThread();

        return new View($pages, $redirects, $request, $stores, $this->access, $this->grants, $this->limits, $session, $this->current);
    }

    private function context(): array
    {
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getId')->willReturn(42);
        $submission->method('getCustomerId')->willReturnCallback(fn () => $this->ownerId);
        return [
            'submission' => $submission,
            'form' => $this->createStub(FormInterface::class),
            'version' => 3,
            'fingerprints' => ['old', 'new'],
            'recipient' => 'guest@example.com',
        ];
    }

    private function assertPrivateHeaders(): void
    {
        foreach (PrivateResponse::HEADERS as $name => $value) {
            $this->assertSame($value, $this->headers[$name] ?? null, $name);
        }
    }

    public function testItIsAGetOnlyActionOutsideTheCustomerAccountArea(): void
    {
        $this->assertTrue(is_subclass_of(View::class, HttpGetActionInterface::class));
        $this->assertFalse(is_subclass_of(View::class, HttpPostActionInterface::class));
        $this->assertFalse(is_subclass_of(View::class, \Magento\Customer\Controller\AccountInterface::class));
    }

    public function testItRedeemsAValidTokenIntoASessionGrantAndRedirectsWithoutTheToken(): void
    {
        $this->asserted = ['grants'];
        $this->params = ['t' => 'TOKEN'];
        $this->redeemed = $this->context();
        $this->grants = $this->double('grants', ThreadGrants::class);
        $this->grants->expects($this->once())->method('grant')->with(42, 3, 'new');

        $this->controller()->execute();

        $this->assertSame([['furanform/thread/view', ['_scope_to_url' => true, '_query' => ['id' => 42]]]], $this->paths);
        $this->assertStringNotContainsString('TOKEN', json_encode($this->paths));
        $this->assertPrivateHeaders();
    }

    public function testItKeepsTheStoreMarkerOnThreadRedirects(): void
    {
        $this->params = ['t' => 'TOKEN'];
        $this->redeemed = $this->context();
        $this->controller()->execute();
        $this->params = ['id' => '42'];
        $this->loaded = $this->context();
        $this->granted = true;
        $this->ownerId = 7;
        $this->sessionCustomerId = 7;
        $this->controller()->execute();

        $this->assertCount(2, $this->paths);
        foreach ($this->paths as [$path, $params]) {
            $this->assertTrue($params['_scope_to_url'], $path);
            $this->assertArrayNotHasKey('_scope', $params, $path);
        }
    }

    public function testItRedirectsAnInvalidTokenWithoutGrantingAnything(): void
    {
        $this->asserted = ['grants'];
        $this->params = ['t' => 'TOKEN'];
        $this->grants = $this->double('grants', ThreadGrants::class);
        $this->grants->expects($this->never())->method('grant');

        $this->controller()->execute();

        $this->assertSame([['furanform/thread/view', ['_scope_to_url' => true]]], $this->paths);
        $this->assertPrivateHeaders();
    }

    public function testItStopsCheckingTokensOnceTheIpIsOverTheLimit(): void
    {
        $this->asserted = ['access'];
        $this->params = ['t' => 'TOKEN'];
        $this->allowAttempt = false;
        $this->redeemed = $this->context();
        $this->access = $this->double('access', ThreadAccess::class);
        $this->access->expects($this->never())->method('redeem');

        $this->controller()->execute();

        $this->assertSame([['furanform/thread/view', ['_scope_to_url' => true]]], $this->paths);
    }

    public function testItShowsTheThreadToAVisitorHoldingAGrant(): void
    {
        $this->params = ['id' => '42'];
        $this->loaded = $this->context();
        $this->granted = true;

        $this->controller()->execute();

        $this->assertSame(42, $this->current->get()['submission']->getId());
        $this->assertNull($this->code);
        $this->assertSame([], $this->paths);
        $this->assertPrivateHeaders();
    }

    public function testItShowsTheSameGenericNotFoundPageForEveryFailure(): void
    {
        $cases = [
            'no grant' => [['id' => '42'], $this->context(), false],
            'unknown, deleted or other store' => [['id' => '42'], null, true],
            'no id' => [[], null, false],
            'id with trailing junk' => [['id' => '42abc'], $this->context(), true],
            'id as an array' => [['id' => ['42']], $this->context(), true],
        ];
        foreach ($cases as $label => [$params, $loaded, $granted]) {
            $this->params = $params;
            $this->loaded = $loaded;
            $this->granted = $granted;
            $this->code = null;
            $this->headers = [];
            $this->controller()->execute();

            $this->assertSame(404, $this->code, $label);
            $this->assertNull($this->current->get(), $label);
            $this->assertPrivateHeaders();
        }
    }

    public function testItSendsTheOwningCustomerToTheirAccountPage(): void
    {
        $this->params = ['id' => '42'];
        $this->loaded = $this->context();
        $this->granted = true;
        $this->ownerId = 7;
        $this->sessionCustomerId = 7;

        $this->controller()->execute();

        $this->assertSame([['furanform/account/submission', ['_query' => ['id' => 42], '_scope_to_url' => true]]], $this->paths);
        $this->assertNull($this->current->get());
    }

    public function testItShowsTheThreadToOtherSignedInCustomersHoldingAGrant(): void
    {
        $this->params = ['id' => '42'];
        $this->loaded = $this->context();
        $this->granted = true;
        $this->ownerId = 7;
        $this->sessionCustomerId = 8;

        $this->controller()->execute();

        $this->assertNotNull($this->current->get());
    }
}
