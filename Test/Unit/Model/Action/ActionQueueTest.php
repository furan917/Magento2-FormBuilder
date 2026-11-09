<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Action;

use Furan\FormBuilder\Model\Action\ActionQueue;
use Furan\FormBuilder\Model\Config;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ActionQueueTest extends TestCase
{
    private function queue(bool $async, ?PublisherInterface $publisher = null, ?LoggerInterface $logger = null): ActionQueue
    {
        $config = $this->createStub(Config::class);
        $config->method('isAsyncActions')->willReturn($async);

        return new ActionQueue(
            $publisher ?? $this->createStub(PublisherInterface::class),
            $config,
            new Json(),
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    public function testItPublishesOnlyIdsAndFlagsNeverTheAnswers(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())->method('publish')
            ->with(ActionQueue::TOPIC, '{"id":41,"action":"crm","attempt":2,"edit":true}');

        $this->assertTrue($this->queue(true, $publisher)->publish(41, 'crm', 2, true));
    }

    public function testItDeclinesWhenBackgroundActionsAreOffOrTheSubmissionIsNotStored(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $this->assertFalse($this->queue(false, $publisher)->publish(41, 'crm', 1, false));
        $this->assertFalse($this->queue(true, $publisher)->publish(0, 'crm', 1, false));
    }

    public function testItDeclinesWhenTheBrokerIsDownSoTheCallerRunsTheActionInline(): void
    {
        $publisher = $this->createStub(PublisherInterface::class);
        $publisher->method('publish')->willThrowException(new \RuntimeException('connection refused'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $this->assertFalse($this->queue(true, $publisher, $logger)->publish(41, 'crm', 1, false));
    }
}
