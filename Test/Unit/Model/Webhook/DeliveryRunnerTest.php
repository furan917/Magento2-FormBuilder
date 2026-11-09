<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\DeliveryClaim;
use Furan\FormBuilder\Model\Webhook\DeliveryRunner;
use Furan\FormBuilder\Model\Webhook\DeliverySender;
use Furan\FormBuilder\Model\Webhook\EndpointSlots;
use Furan\FormBuilder\Model\Webhook\RunOutcome;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DeliveryRunnerTest extends TestCase
{
    public function testItNeverSendsMoreThanTwoDeliveriesAtOnceToOneEndpoint(): void
    {
        $leased = [];
        $slots = $this->createStub(EndpointSlots::class);
        $slots->method('acquire')->willReturnCallback(function (int $endpoint) use (&$leased): ?int {
            foreach ([0, 1] as $slot) {
                if (!isset($leased[$endpoint][$slot])) {
                    $leased[$endpoint][$slot] = true;
                    return $slot;
                }
            }
            return null;
        });
        $slots->method('endpointExists')->willReturn(true);
        $claim = $this->createStub(DeliveryClaim::class);
        $claimed = [];
        $claim->method('claim')->willReturnCallback(function (int $id) use (&$claimed): bool {
            $claimed[] = $id;
            return true;
        });
        $sent = [];
        $sender = $this->createStub(DeliverySender::class);
        $nested = null;
        $runner = null;
        $sender->method('send')->willReturnCallback(function (int $id) use (&$sent, &$nested, &$runner): void {
            $sent[] = $id;
            // While 1 is in flight a second arrives, and while both are in flight a third one.
            if ($id === 1) {
                $nested[2] = $runner->run(2, 9, WebhookFixtures::NOW)->name;
            } elseif ($id === 2) {
                $nested[3] = $runner->run(3, 9, WebhookFixtures::NOW)->name;
            }
        });
        $released = [];
        $slots->method('release')->willReturnCallback(function (int $e, int $slot) use (&$released, &$leased): void {
            $released[] = $slot;
            unset($leased[$e][$slot]);
        });
        $runner = new DeliveryRunner($claim, $slots, $sender, $this->createStub(LoggerInterface::class));

        $this->assertSame(RunOutcome::Handled, $runner->run(1, 9, WebhookFixtures::NOW));

        $this->assertSame([3 => 'SlotBusy', 2 => 'Handled'], $nested);
        $this->assertSame([1, 2], $sent);
        $this->assertSame([1, 2], $claimed, 'the third was left unclaimed because it got no slot');
        $this->assertEqualsCanonicalizing([0, 1], $released);
        $this->assertSame([], $leased[9]);
    }

    public function testItReleasesTheSlotWhenTheClaimIsLost(): void
    {
        $slots = $this->createMock(EndpointSlots::class);
        $slots->method('acquire')->willReturn(0);
        $slots->expects($this->once())->method('release')->with(9, 0, WebhookFixtures::NOW);
        $claim = $this->createStub(DeliveryClaim::class);
        $claim->method('claim')->willReturn(false);
        $sender = $this->createMock(DeliverySender::class);
        $sender->expects($this->never())->method('send');

        $runner = new DeliveryRunner($claim, $slots, $sender, $this->createStub(LoggerInterface::class));

        $this->assertSame(RunOutcome::Skipped, $runner->run(1, 9, WebhookFixtures::NOW));
    }

    public function testItContainsAFailureOfTheClaimAndFreesTheSlot(): void
    {
        $slots = $this->createMock(EndpointSlots::class);
        $slots->method('acquire')->willReturn(1);
        $slots->expects($this->once())->method('release');
        $claim = $this->createStub(DeliveryClaim::class);
        $claim->method('claim')->willThrowException(new \RuntimeException('deadlock'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $runner = new DeliveryRunner($claim, $slots, $this->createStub(DeliverySender::class), $logger);

        $this->assertSame(RunOutcome::Skipped, $runner->run(1, 9, WebhookFixtures::NOW));
    }

    public function testItCancelsADeliveryWhoseEndpointRowIsGone(): void
    {
        $slots = $this->createMock(EndpointSlots::class);
        $slots->method('acquire')->willReturn(null);
        $slots->method('endpointExists')->with(9)->willReturn(false);
        $claim = $this->createMock(DeliveryClaim::class);
        $claim->expects($this->once())->method('cancel')->with(1, 'endpoint_inactive');
        $claim->expects($this->never())->method('claim');
        $sender = $this->createMock(DeliverySender::class);
        $sender->expects($this->never())->method('send');

        $runner = new DeliveryRunner($claim, $slots, $sender, $this->createStub(LoggerInterface::class));

        $this->assertSame(RunOutcome::Handled, $runner->run(1, 9, WebhookFixtures::NOW));
    }
}
