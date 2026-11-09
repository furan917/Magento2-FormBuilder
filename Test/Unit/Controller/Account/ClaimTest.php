<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Account;

use Furan\FormBuilder\Controller\Account\Claim;
use Furan\FormBuilder\Model\Claim\ClaimService;
use Furan\FormBuilder\Model\Claim\ClaimToken;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Security\SigningKeys;
use Furan\FormBuilder\Model\Submission\EmailHasher;
use Magento\Customer\Controller\AccountInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Furan\FormBuilder\Model\Customer\Impersonation;
use Psr\Log\LoggerInterface;

class ClaimTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private ClaimService&Stub $service;
    private ManagerInterface&Stub $messages;
    private Redirect&Stub $redirect;
    private Forward&Stub $forward;
    private Http&MockObject $request;
    private Session&Stub $session;
    private CustomerInterface&Stub $customer;
    private EmailHasher $hasher;
    private ClaimToken $token;
    private bool $enabled = true;
    private bool $impersonating = false;
    /** @var array<string, string> */
    private array $headers = [];

    private function controller(int $customerId = 7, string $email = 'jo@example.com', ?string $param = null): Claim
    {
        $this->crypto();
        $this->service = $this->double('service', ClaimService::class);
        $this->messages = $this->double('messages', ManagerInterface::class);
        $this->redirect = $this->createStub(Redirect::class);
        $this->redirect->method('setPath')->willReturnSelf();
        $this->redirect->method('setHeader')->willReturnCallback(function (string $name, string $value) {
            $this->headers[$name] = $value;
            return $this->redirect;
        });
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);
        $this->forward = $this->createStub(Forward::class);
        $this->forward->method('forward')->willReturnSelf();
        $forwardFactory = $this->createStub(ForwardFactory::class);
        $forwardFactory->method('create')->willReturn($this->forward);
        $this->request = $this->createMock(Http::class);
        $this->request->method('getParam')->with('token')->willReturn($param);
        $this->customer = $this->createStub(CustomerInterface::class);
        $this->customer->method('getEmail')->willReturn($email);
        $this->customer->method('getWebsiteId')->willReturn('2');
        $this->session = $this->createStub(Session::class);
        $this->session->method('getCustomerId')->willReturn($customerId ?: null);
        $this->session->method('getCustomerData')->willReturn($this->customer);
        $config = $this->createStub(Config::class);
        $config->method('isClaimEnabled')->willReturnCallback(fn(): bool => $this->enabled);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $impersonation = $this->createStub(Impersonation::class);
        $impersonation->method('isActive')->willReturnCallback(fn (): bool => $this->impersonating);
        $impersonation->method('accountRefusal')->willReturn('Not while impersonating.');

        return new Claim(
            $redirectFactory,
            $forwardFactory,
            $this->session,
            $this->request,
            $this->token,
            $this->service,
            $this->hasher,
            $config,
            $storeManager,
            $this->messages,
            $this->createStub(LoggerInterface::class),
            $impersonation
        );
    }

    private function crypto(): void
    {
        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('get')->willReturn('k0 k1');
        $this->hasher = new EmailHasher($deployment);
        $this->token = new ClaimToken(new SigningKeys($deployment));
    }

    private function tokenFor(int $customerId, string $email): string
    {
        $this->crypto();
        return $this->token->issue($customerId, $this->hasher->hash($email)['hash']);
    }

    public function testItKeepsTheTokenOutOfRefererHeadersAndCaches(): void
    {
        $this->controller(7, 'jo@example.com', $this->tokenFor(7, 'jo@example.com'))->execute();

        $this->assertSame('no-referrer', $this->headers['Referrer-Policy']);
        $this->assertSame('no-store, private', $this->headers['Cache-Control']);
    }

    public function testItRefusesToClaimWhileAnAdminIsLoggedInAsTheCustomer(): void
    {
        $this->asserted = ['service', 'messages'];
        $this->impersonating = true;
        $controller = $this->controller(7, 'jo@example.com', $this->tokenFor(7, 'jo@example.com'));
        $this->service->expects($this->never())->method('claim');
        $this->messages->expects($this->once())->method('addErrorMessage')->with('Not while impersonating.');

        $this->assertSame($this->redirect, $controller->execute());
    }

    public function testItIsAnAccountActionOutsideThePluginAllowList(): void
    {
        $this->assertContains(AccountInterface::class, class_implements(Claim::class));
    }

    public function testItLinksOnlyWhenTheLoggedInCustomerMatchesTheToken(): void
    {
        $this->asserted = ['service', 'messages'];
        $controller = $this->controller(7, 'jo@example.com', $this->tokenFor(7, 'jo@example.com'));
        $this->service->expects($this->once())->method('claim')->with(7, 'jo@example.com', 2)->willReturn(3);
        $this->messages->expects($this->once())->method('addSuccessMessage');
        $controller->execute();

        // token issued to customer 8, opened by customer 7
        $controller = $this->controller(7, 'jo@example.com', $this->tokenFor(8, 'jo@example.com'));
        $this->service->expects($this->never())->method('claim');
        $this->messages->expects($this->once())->method('addErrorMessage');
        $controller->execute();
    }

    public function testItNeverLinksByEmailWithoutTheSignedLink(): void
    {
        $this->asserted = ['service'];
        foreach ([null, '', 'garbage'] as $param) {
            $controller = $this->controller(7, 'jo@example.com', $param);
            $this->service->expects($this->never())->method('claim');
            $controller->execute();
        }
        // a guest session never reaches the service either
        $controller = $this->controller(0, 'jo@example.com', 'x');
        $this->service->expects($this->never())->method('claim');
        $controller->execute();
    }

    public function testItRejectsAClaimTokenAfterTheCustomersEmailHasChanged(): void
    {
        $this->asserted = ['service', 'messages'];
        $stale = $this->tokenFor(7, 'old@example.com');
        $controller = $this->controller(7, 'new@example.com', $stale);
        $this->service->expects($this->never())->method('claim');
        $this->messages->expects($this->once())->method('addErrorMessage');
        $controller->execute();
    }
}
