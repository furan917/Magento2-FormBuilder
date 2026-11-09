<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Observer;

use Furan\FormBuilder\Model\Claim\ClaimMailer;
use Furan\FormBuilder\Model\Claim\ClaimService;
use Furan\FormBuilder\Model\Claim\ClaimToken;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Mail\MailQueue;
use Furan\FormBuilder\Model\Submission\EmailHasher;
use Furan\FormBuilder\Observer\OfferClaim;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\Template\TransportBuilderFactory;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OfferClaimTest extends TestCase
{
    private ClaimService&Stub $service;
    private CacheInterface&Stub $cache;
    private TransportInterface&Stub $transport;
    private LoggerInterface&Stub $logger;
    private Config&Stub $config;
    private ClaimToken&Stub $token;
    private UrlInterface&Stub $url;
    private TransportBuilder&Stub $builder;
    /** @var array<int, array{0: string, 1: array}> */
    private array $calls = [];
    private ?MailQueue $queue = null;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?CacheInterface $cache = null, ?LoggerInterface $logger = null, ?ClaimService $service = null, ?TransportInterface $transport = null): void
    {
        $this->calls = [];
        $this->service = $service ?? $this->createStub(ClaimService::class);
        $this->cache = $cache ?? $this->createStub(CacheInterface::class);
        $this->transport = $transport ?? $this->createStub(TransportInterface::class);
        $this->logger = $logger ?? $this->createStub(LoggerInterface::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isClaimEnabled')->willReturn(true);
        $this->config->method('getSenderIdentity')->willReturn('support');
        $this->token = $this->createStub(ClaimToken::class);
        $this->token->method('issue')->willReturn('TOKEN');
        $this->url = $this->createStub(UrlInterface::class);
        $this->url->method('getUrl')->willReturnCallback(
            fn(string $route): string => $route === 'furanform/account/claim'
                ? 'https://shop.test/claim?token=TOKEN'
                : 'https://shop.test/wrong-route'
        );
        $this->builder = $this->createStub(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo'] as $m) {
            $this->builder->method($m)->willReturnCallback(function (...$args) use ($m) {
                $this->calls[] = [$m, $args];
                return $this->builder;
            });
        }
        $this->builder->method('getTransport')->willReturn($this->transport);
    }

    private function fire(?DataObject $customer = null): void
    {
        $factory = $this->createStub(TransportBuilderFactory::class);
        $factory->method('create')->willReturn($this->builder);
        $config = $this->createStub(\Magento\Framework\App\DeploymentConfig::class);
        $config->method('get')->willReturn('k0');
        $hasher = new EmailHasher($config);
        $observer = new OfferClaim(
            $this->config,
            $this->service,
            $hasher,
            $this->cache,
            $this->createStub(StoreManagerInterface::class),
            $this->logger,
            new ClaimMailer($this->config, $this->token, $hasher, $factory, $this->createStub(StateInterface::class), $this->url, $this->createStub(StoreManagerInterface::class)),
            $this->queue ?? $this->createStub(MailQueue::class)
        );
        $customer ??= $this->customer();
        $observer->execute(new Observer(['event' => new Event(['customer' => $customer])]));
    }

    private function customer(): DataObject
    {
        return new class (['id' => '8', 'email' => 'Jo@Example.com', 'website_id' => '1', 'store_id' => '2']) extends DataObject {
            public function getName(): string
            {
                return 'Jo';
            }
        };
    }

    public function testItEmailsAClaimLinkOnlyWhenUnlinkedGuestSubmissionsExistForTheEmail(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $this->fixture(transport: $transport);
        $this->service->method('hasUnlinked')->willReturnOnConsecutiveCalls(false, true);
        $transport->expects($this->once())->method('sendMessage');

        $this->fire();
        $this->assertSame([], $this->calls);
        $this->fire();
        $this->assertContains(['addTo', ['Jo@Example.com', 'Jo']], $this->calls);
        $vars = array_values(array_filter($this->calls, fn(array $c): bool => $c[0] === 'setTemplateVars'))[0][1][0];
        $this->assertSame('https://shop.test/claim?token=TOKEN', $vars['claim_url']);
    }

    public function testItSendsAtMostOneClaimEmailPerCustomerPerDay(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $transport = $this->createMock(TransportInterface::class);
        $this->fixture(cache: $cache, transport: $transport);
        $this->service->method('hasUnlinked')->willReturn(true);
        $cache->method('load')->with('ffb_claim_8')->willReturnOnConsecutiveCalls(false, '1');
        $cache->expects($this->once())->method('save')->with('1', 'ffb_claim_8', [], 86400);
        $transport->expects($this->once())->method('sendMessage');

        $this->fire();
        $this->fire();
    }

    public function testItNeverFailsTheLoginWhenTheClaimEmailCannotBeSent(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $this->fixture(cache: $cache, logger: $logger);
        $this->service->method('hasUnlinked')->willReturn(true);
        $this->transport->method('sendMessage')->willThrowException(new \RuntimeException('smtp down jo@example.com'));
        $cache->expects($this->never())->method('save');
        $logger->expects($this->once())->method('error')
            ->with($this->logicalNot($this->stringContains('example.com')));

        $this->fire();
    }

    public function testItDoesNothingWhenClaimsAreDisabled(): void
    {
        $service = $this->createMock(ClaimService::class);
        $this->fixture(service: $service);
        $config = $this->createStub(Config::class);
        $config->method('isClaimEnabled')->willReturn(false);
        $this->config = $config;
        $service->expects($this->never())->method('hasUnlinked');

        $this->fire();
    }

    public function testItQueuesTheClaimEmailInsteadOfSendingDuringLoginWhenTheQueueAccepts(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $transport = $this->createMock(TransportInterface::class);
        $this->fixture(cache: $cache, transport: $transport);
        $this->service->method('hasUnlinked')->willReturn(true);
        $queue = $this->createMock(MailQueue::class);
        $queue->expects($this->once())->method('publishClaim')->with(8, 2)->willReturn(true);
        $this->queue = $queue;
        $transport->expects($this->never())->method('sendMessage');
        $cache->expects($this->once())->method('save')->with('1', 'ffb_claim_8', [], 86400);

        $this->fire();
    }
}
