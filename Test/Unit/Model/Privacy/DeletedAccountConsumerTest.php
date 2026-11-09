<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Privacy;

use Furan\FormBuilder\Model\Privacy\DeletedAccountConsumer;
use Furan\FormBuilder\Model\Privacy\PendingProcessor;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DeletedAccountConsumerTest extends TestCase
{
    public function testItProcessesThePendingRowsOfTheCustomerNamedInTheWakeUp(): void
    {
        $processor = $this->createMock(PendingProcessor::class);
        $processor->expects($this->once())->method('processCustomer')->with(9);

        (new DeletedAccountConsumer($processor, new Json(), $this->createStub(LoggerInterface::class)))
            ->process('{"customer_id":9}');
    }

    public function testItDropsAMessageWithoutACustomer(): void
    {
        $processor = $this->createMock(PendingProcessor::class);
        $processor->expects($this->never())->method('processCustomer');

        (new DeletedAccountConsumer($processor, new Json(), $this->createStub(LoggerInterface::class)))
            ->process('{"ids":[4]}');
    }

    public function testItLogsAnUnreadableMessageWithoutThrowing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        (new DeletedAccountConsumer($this->createStub(PendingProcessor::class), new Json(), $logger))->process('{');
    }
}
