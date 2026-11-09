<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Mail;

use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Mail\MailQueue;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MailQueueTest extends TestCase
{
    private function queue(bool $async, ?PublisherInterface $publisher = null, ?LoggerInterface $logger = null): MailQueue
    {
        $config = $this->createStub(Config::class);
        $config->method('isAsyncMail')->willReturn($async);
        return new MailQueue(
            $publisher ?? $this->createStub(PublisherInterface::class),
            $config,
            new Json(),
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    public function testItPublishesOnlyTheIdNeverTheAnswers(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())->method('publish')
            ->with(MailQueue::TOPIC, '{"kind":"claim","id":8,"store":2}');

        $this->assertTrue($this->queue(true, $publisher)->publishClaim(8, 2));
    }

    public function testItPublishesACustomerReplyByMessageIdOnly(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())->method('publish')->with(MailQueue::TOPIC, '{"kind":"reply","id":31}');

        $this->assertTrue($this->queue(true, $publisher)->publishReply(31));
    }

    public function testItLeavesCustomerReplySendingToTheCallerWhenBackgroundMailIsOffOrTheBrokerIsDown(): void
    {
        $off = $this->createMock(PublisherInterface::class);
        $off->expects($this->never())->method('publish');
        $down = $this->createStub(PublisherInterface::class);
        $down->method('publish')->willThrowException(new \RuntimeException('connection refused'));

        $this->assertFalse($this->queue(false, $off)->publishReply(31));
        $this->assertFalse($this->queue(true, $down)->publishReply(31));
    }

    public function testItLeavesSendingToTheCallerWhenBackgroundMailIsOff(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $this->assertFalse($this->queue(false, $publisher)->publishClaim(8, 2));
    }

    public function testItFallsBackToInlineSendingWhenTheBrokerIsDown(): void
    {
        $publisher = $this->createStub(PublisherInterface::class);
        $publisher->method('publish')->willThrowException(new \RuntimeException('connection refused'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $this->assertFalse($this->queue(true, $publisher, $logger)->publishClaim(8, 2));
    }
}
