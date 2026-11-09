<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Observer;

use Furan\FormBuilder\Model\Privacy\DeletedAccountQueue;
use Furan\FormBuilder\Observer\CustomerDeleteCommitAfter;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CustomerDeleteCommitAfterTest extends TestCase
{
    private function fire(DeletedAccountQueue $queue, LoggerInterface $logger): void
    {
        $customer = new DataObject(['id' => 9, 'email' => 'a@b.test']);
        (new CustomerDeleteCommitAfter($queue, $logger))
            ->execute(new Observer(['event' => new Event(['customer' => $customer])]));
    }

    public function testItQueuesTheCapturedSubmissionsOnceTheDeleteHasCommitted(): void
    {
        $queue = $this->createMock(DeletedAccountQueue::class);
        $queue->expects($this->once())->method('publish')->with(9);

        $this->fire($queue, $this->createStub(LoggerInterface::class));
    }

    public function testItIsWiredToTheCustomerDeleteCommit(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/events.xml');
        $hits = $xml->xpath('//event[@name="customer_delete_commit_after"]/observer[@instance="' . CustomerDeleteCommitAfter::class . '"]');
        $this->assertCount(1, $hits);
    }

    public function testItLogsAFailedPublishWithoutTheEmailAndWithoutThrowingAfterTheCommit(): void
    {
        $queue = $this->createStub(DeletedAccountQueue::class);
        $queue->method('publish')->willThrowException(new \RuntimeException('down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->callback(static fn (string $m): bool => !str_contains($m, 'a@b.test') && str_contains($m, '9'))
        );

        $this->fire($queue, $logger);
    }
}
