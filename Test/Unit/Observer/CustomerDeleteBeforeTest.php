<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Observer;

use Furan\FormBuilder\Model\Privacy\DeletedAccountQueue;
use Furan\FormBuilder\Observer\CustomerDeleteBefore;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CustomerDeleteBeforeTest extends TestCase
{
    private function fire(DeletedAccountQueue $queue, LoggerInterface $logger, array $event): void
    {
        (new CustomerDeleteBefore($queue, $logger))->execute(new Observer(['event' => new Event($event)]));
    }

    public function testItCapturesTheAccountsSubmissionsBeforeTheCustomerRowIsRemoved(): void
    {
        $queue = $this->createMock(DeletedAccountQueue::class);
        $queue->expects($this->once())->method('capture')->with(9);
        $queue->expects($this->never())->method('publish');

        $this->fire($queue, $this->createStub(LoggerInterface::class), ['customer' => new DataObject(['id' => 9])]);
    }

    public function testItIgnoresACustomerWithoutAnId(): void
    {
        $queue = $this->createMock(DeletedAccountQueue::class);
        $queue->expects($this->never())->method('capture');

        $this->fire($queue, $this->createStub(LoggerInterface::class), ['data_object' => new DataObject()]);
    }

    public function testItRunsBeforeTheCustomerRowIsRemoved(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/events.xml');
        $hits = $xml->xpath('//event[@name="customer_delete_before"]/observer[@instance="' . CustomerDeleteBefore::class . '"]');
        $this->assertCount(1, $hits);
    }

    public function testItLogsWithoutTheEmailAndAbortsTheDeleteWhenTheSubmissionsCannotBeCaptured(): void
    {
        $queue = $this->createStub(DeletedAccountQueue::class);
        $queue->method('capture')->willThrowException(new \RuntimeException('boom'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->callback(static fn (string $m): bool => !str_contains($m, 'a@b.test') && str_contains($m, '9'))
        );

        $this->expectException(\RuntimeException::class);
        $this->fire($queue, $logger, ['customer' => new DataObject(['id' => 9, 'email' => 'a@b.test'])]);
    }
}
