<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Customer\Impersonation;
use Furan\FormBuilder\Model\Http\ClientIp;
use Furan\FormBuilder\Model\Submission\SubmissionInputFactory;
use Furan\FormBuilder\Model\Upload\FilesNormalizer;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Request\Http;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SubmissionInputFactoryTest extends TestCase
{
    private Session&Stub $session;
    private Config&Stub $config;

    protected function setUp(): void
    {
        $this->session = $this->createStub(Session::class);
        $this->config = $this->createStub(Config::class);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $server
     */
    private function request(array $params = [], array $server = [], mixed $files = []): Http&MockObject
    {
        $request = $this->createMock(Http::class);
        $request->method('getParam')->willReturnCallback(static fn (string $k, $d = null) => $params[$k] ?? $d);
        $request->method('getServer')->willReturnCallback(static fn (string $k) => $server[$k] ?? null);
        $request->method('getFiles')->with('files')->willReturn($files);
        return $request;
    }

    private function factory(Http $request): SubmissionInputFactory
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(4);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new SubmissionInputFactory(
            $this->session,
            $storeManager,
            new ClientIp($request, $this->config),
            new FilesNormalizer(),
            new Impersonation($this->session)
        );
    }

    public function testItBuildsASubmissionInputFromTheHttpRequest(): void
    {
        $request = $this->request([
            'fields' => ['name' => 'Ann'],
            'ffb_token' => 'tok',
            'ffb_edit' => 'edit.tok',
            'ffb_product_id' => '9',
            'ffb_website' => 'spam',
            'g-recaptcha-response' => 'captcha',
        ], [
            'CONTENT_LENGTH' => '1234',
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_USER_AGENT' => 'Agent/1.0',
            'HTTP_REFERER' => 'https://shop.test/form',
        ], ['cv' => ['name' => 'a.pdf', 'type' => '', 'tmp_name' => '/tmp/x', 'error' => '0', 'size' => '3'], 'junk' => 'x']);

        $input = $this->factory($request)->fromHttp($request);

        $this->assertSame(['name' => 'Ann'], $input->fields);
        $this->assertSame(
            ['cv' => ['name' => 'a.pdf', 'type' => '', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 3]],
            $input->files
        );
        $this->assertSame('tok', $input->formToken);
        $this->assertSame('edit.tok', $input->editToken);
        $this->assertSame(9, $input->productId);
        $this->assertSame('spam', $input->honeypot);
        $this->assertSame('captcha', $input->recaptchaResponse);
        $this->assertSame(1234, $input->contentLength);
        $this->assertSame('203.0.113.9', $input->clientIp);
        $this->assertSame('Agent/1.0', $input->userAgent);
        $this->assertSame('https://shop.test/form', $input->referrer);
    }

    public function testItIgnoresParamsOfTheWrongType(): void
    {
        $request = $this->request([
            'fields' => 'oops', 'ffb_token' => ['x'], 'ffb_edit' => ['x'], 'ffb_website' => ['x'],
            'g-recaptcha-response' => ['x'], 'ffb_product_id' => ['9'],
        ]);

        $input = $this->factory($request)->fromHttp($request);

        $this->assertSame([], $input->fields);
        $this->assertSame('', $input->formToken);
        $this->assertSame('', $input->editToken);
        $this->assertSame('', $input->recaptchaResponse);
        $this->assertSame(0, $input->productId);
        $this->assertSame(0, $input->contentLength);
        $this->assertSame('', $input->userAgent);
    }

    public function testItTreatsAnyNonStringHoneypotValueAsFilled(): void
    {
        $filled = $this->request(['ffb_website' => ['x']]);
        $empty = $this->request(['ffb_website' => '']);

        $this->assertNotSame('', $this->factory($filled)->fromHttp($filled)->honeypot);
        $this->assertSame('', $this->factory($empty)->fromHttp($empty)->honeypot);
    }

    public function testItCapsTheUserAgentAndReferrerAt512Characters(): void
    {
        $request = $this->request([], ['HTTP_USER_AGENT' => str_repeat('a', 900), 'HTTP_REFERER' => str_repeat('r', 900)]);

        $input = $this->factory($request)->fromHttp($request);

        $this->assertSame(512, strlen($input->userAgent));
        $this->assertSame(512, strlen($input->referrer));
    }

    public function testItKeepsTheClientIpFromTheTrustedProxyRules(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7'];
        $this->config->method('getTrustedProxies')->willReturn(['10.0.0.0/8']);
        $request = $this->request([], $server);
        $this->assertSame('198.51.100.7', $this->factory($request)->fromHttp($request)->clientIp);
    }

    public function testItIgnoresForwardedForFromAnUntrustedPeer(): void
    {
        $this->config->method('getTrustedProxies')->willReturn([]);
        $request = $this->request([], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1']);
        $this->assertSame('203.0.113.9', $this->factory($request)->fromHttp($request)->clientIp);
    }

    public function testItReadsTheCustomerAndStoreFromTheSessionAndStoreManager(): void
    {
        $session = $this->session = $this->createMock(Session::class);
        $data = $this->createStub(CustomerInterface::class);
        $data->method('getEmail')->willReturn('c@x.co');
        $this->session->method('isLoggedIn')->willReturn(true);
        $this->session->method('getCustomerId')->willReturn(5);
        $this->session->method('getCustomerGroupId')->willReturn(3);
        $this->session->method('getCustomerData')->willReturn($data);
        $session->method('getData')->with('logged_as_customer_admind_id')->willReturn(8);
        $request = $this->request();

        $input = $this->factory($request)->fromHttp($request);

        $this->assertSame(5, $input->customerId);
        $this->assertSame(3, $input->customerGroupId);
        $this->assertSame('c@x.co', $input->customerEmail);
        $this->assertTrue($input->impersonated);
        $this->assertSame(4, $input->storeId);
    }

    public function testItBuildsAGuestInputWithoutLoadingTheCustomer(): void
    {
        $session = $this->session = $this->createMock(Session::class);
        $this->session->method('isLoggedIn')->willReturn(false);
        $this->session->method('getCustomerId')->willReturn(0);
        $this->session->method('getCustomerGroupId')->willReturn(1);
        $session->expects($this->never())->method('getCustomerData');
        $request = $this->request();

        $input = $this->factory($request)->fromHttp($request);

        $this->assertSame(0, $input->customerId);
        $this->assertSame(0, $input->customerGroupId);
        $this->assertSame('', $input->customerEmail);
        $this->assertFalse($input->impersonated);
    }
}
