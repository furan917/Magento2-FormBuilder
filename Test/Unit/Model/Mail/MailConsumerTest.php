<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Mail;

use Furan\FormBuilder\Model\Claim\ClaimMailer;
use Furan\FormBuilder\Model\Claim\ClaimService;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Mail\MailConsumer;
use Furan\FormBuilder\Model\Thread\QueuedCustomerReply;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MailConsumerTest extends TestCase
{
    private CustomerRepositoryInterface&Stub $customers;
    private ClaimService&Stub $claims;
    private ClaimMailer&Stub $claimMailer;
    private Config&Stub $config;
    private LoggerInterface&Stub $logger;
    private ?QueuedCustomerReply $replies = null;

    protected function setUp(): void
    {
        $this->customers = $this->createStub(CustomerRepositoryInterface::class);
        $this->claims = $this->createStub(ClaimService::class);
        $this->claimMailer = $this->createStub(ClaimMailer::class);
        $this->config = $this->createStub(Config::class);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    private function consumer(): MailConsumer
    {
        return new MailConsumer(
            $this->customers, $this->claims, $this->claimMailer, $this->config, new Json(), $this->logger,
            $this->storeScope(), $this->replies ?? $this->createStub(QueuedCustomerReply::class)
        );
    }

    public function testItNeverThrowsSoABadMessageCannotBlockTheQueue(): void
    {
        $logger = $this->logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->consumer()->process('not json');
    }

    public function testItRechecksAQueuedClaimBeforeSendingIt(): void
    {
        $claimMailer = $this->claimMailer = $this->createMock(ClaimMailer::class);
        $customers = $this->customers = $this->createMock(CustomerRepositoryInterface::class);
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('jo@example.com');
        $customer->method('getStoreId')->willReturn(2);
        $customer->method('getWebsiteId')->willReturn(1);
        $customer->method('getFirstname')->willReturn('Jo');
        $customer->method('getLastname')->willReturn('Bloggs');
        $customers->method('getById')->with(8)->willReturn($customer);
        $this->config->method('isClaimEnabled')->willReturn(true);
        $this->claims->method('hasUnlinked')->willReturnOnConsecutiveCalls(true, false);
        $claimMailer->expects($this->once())->method('send')->with(8, 'jo@example.com', 'Jo Bloggs', 3);

        $this->consumer()->process('{"kind":"claim","id":8,"store":3}');
        $this->consumer()->process('{"kind":"claim","id":8,"store":3}');
    }

    public function testItSendsAQueuedCustomerReplyByMessageId(): void
    {
        $replies = $this->replies = $this->createMock(QueuedCustomerReply::class);
        $replies->expects($this->once())->method('send')->with(31);

        $this->consumer()->process('{"kind":"reply","id":31}');
    }

    /** @var int[] */
    private array $scopes = [];

    private function storeScope(): \Furan\FormBuilder\Model\Email\StoreScope
    {
        $scope = $this->createStub(\Furan\FormBuilder\Model\Email\StoreScope::class);
        $scope->method('run')->willReturnCallback(function (int $storeId, callable $work) {
            $this->scopes[] = $storeId;
            return $work();
        });
        return $scope;
    }
}
