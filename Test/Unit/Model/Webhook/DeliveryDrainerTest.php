<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\DeliveryClaim;
use Furan\FormBuilder\Model\Webhook\DeliveryDrainer;
use Furan\FormBuilder\Model\Webhook\DeliveryRunner;
use Furan\FormBuilder\Model\Webhook\DeliveryStatus;
use Furan\FormBuilder\Model\Webhook\EndpointRepository;
use Furan\FormBuilder\Model\Webhook\RunOutcome;
use Furan\FormBuilder\Model\Webhook\UrlPolicy;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DeliveryDrainerTest extends TestCase
{
    /** @var array<int, array{active: bool, circuit_open_until: ?string}> */
    private array $states = [];

    /** @var array<int, array<int, int[]>> status => endpoint id => due delivery ids still open */
    private array $due = [];

    /** @var array<int, int> endpoint id => pending rows that are not test sends */
    private array $backlog = [];

    /** @var array<int, array{0: int, 1: int, 2: ?array, 3: int}> status, endpoint, cursor, limit */
    private array $seeks = [];

    /** @var int[] endpoint ids, one entry per cancel batch */
    private array $cancels = [];

    /** @var array<int, int> */
    private array $cancelLimits = [];

    /** @var int[] */
    private array $ran = [];

    /** @var array<int, RunOutcome> endpoint id => outcome, default Handled */
    private array $outcomes = [];

    private int $locks = 0;

    private int $unlocks = 0;

    private int $time = WebhookFixtures::NOW;

    private int $secondsPerSend = 0;

    private bool $failScan = false;

    private function drainer(
        bool $enabled = true,
        bool $lockWon = true,
        int $perEndpoint = 2,
        int $maxSends = 100,
        int $maxSlotMisses = 100
    ): DeliveryDrainer {
        $claim = $this->createStub(DeliveryClaim::class);
        $claim->method('dueForEndpoint')->willReturnCallback(
            function (int $status, int $endpoint, int $now, int $limit, ?array $after): array {
                if ($this->failScan) {
                    throw new \RuntimeException('gone away');
                }
                $this->seeks[] = [$status, $endpoint, $after, $limit];
                $rows = [];
                foreach ($this->due[$status][$endpoint] ?? [] as $id) {
                    if ($after === null || $id > $after[1]) {
                        $rows[] = ['delivery_id' => $id, 'next_attempt_at' => '2027-01-15 08:00:00'];
                    }
                }

                return array_slice($rows, 0, $limit);
            }
        );
        $claim->method('cancelPending')->willReturnCallback(function (int $endpoint, int $limit): int {
            $this->cancels[] = $endpoint;
            $this->cancelLimits[] = $limit;
            $done = min($limit, $this->backlog[$endpoint] ?? 0);
            if (isset($this->backlog[$endpoint]) && $this->backlog[$endpoint] < PHP_INT_MAX) {
                $this->backlog[$endpoint] -= $done;
            }

            return $done;
        });
        $runner = $this->createStub(DeliveryRunner::class);
        $runner->method('run')->willReturnCallback(function (int $id, int $endpoint): RunOutcome {
            $this->ran[] = $id;
            $outcome = $this->outcomes[$endpoint] ?? RunOutcome::Handled;
            if ($outcome === RunOutcome::Handled) {
                $this->time += $this->secondsPerSend;
                foreach ($this->due as $status => $byEndpoint) {
                    $this->due[$status][$endpoint] = array_values(array_diff($byEndpoint[$endpoint] ?? [], [$id]));
                }
            }

            return $outcome;
        });
        $endpoints = $this->createStub(EndpointRepository::class);
        $endpoints->method('routingStates')->willReturnCallback(fn (): array => $this->states);
        $policy = $this->createStub(UrlPolicy::class);
        $policy->method('isEnabled')->willReturn($enabled);
        $lock = $this->createStub(LockManagerInterface::class);
        $lock->method('lock')->willReturnCallback(function (string $name, int $timeout) use ($lockWon): bool {
            $this->assertSame('furanform_webhook_drain', $name);
            $this->assertSame(0, $timeout);
            $this->locks++;
            return $lockWon;
        });
        $lock->method('unlock')->willReturnCallback(function (): bool {
            $this->unlocks++;
            return true;
        });

        return new DeliveryDrainer(
            $claim,
            $runner,
            $endpoints,
            $policy,
            $lock,
            $this->createStub(LoggerInterface::class),
            fn (): int => $this->time,
            $perEndpoint,
            $maxSends,
            $maxSlotMisses
        );
    }

    private function active(int $endpoint, ?string $circuit = null): void
    {
        $this->states[$endpoint] = ['active' => true, 'circuit_open_until' => $circuit];
    }

    private function inactive(int $endpoint): void
    {
        $this->states[$endpoint] = ['active' => false, 'circuit_open_until' => null];
    }

    /** @param int[] $ids */
    private function due(int $status, int $endpoint, array $ids): void
    {
        $this->due[$status][$endpoint] = $ids;
    }

    /** @return array<int, int[]> endpoint ids seeked per status */
    private function seekedEndpoints(): array
    {
        $seeked = [];
        foreach ($this->seeks as [$status, $endpoint]) {
            $seeked[$status][$endpoint] = $endpoint;
        }

        return array_map(array_values(...), $seeked);
    }

    public function testItDrainsDueDeliveriesWalkingOnFromTheLastRowSeenUnderALock(): void
    {
        $this->active(1);
        $this->due(DeliveryStatus::PENDING, 1, [1, 2, 3]);
        $this->due(DeliveryStatus::DELIVERING, 1, [4]);

        $this->drainer()->drain();

        $this->assertSame([1, 2, 3, 4], $this->ran);
        $this->assertSame(
            [
                [DeliveryStatus::PENDING, 1, null, 2],
                [DeliveryStatus::PENDING, 1, ['2027-01-15 08:00:00', 2], 2],
                [DeliveryStatus::DELIVERING, 1, null, 2],
            ],
            $this->seeks
        );
        $this->assertSame(1, $this->locks);
        $this->assertSame(1, $this->unlocks);
    }

    public function testItStopsWithinTheTimeBudget(): void
    {
        $this->secondsPerSend = 30;
        $this->active(1);
        $this->due(DeliveryStatus::PENDING, 1, [1, 2, 3, 4]);

        $this->drainer()->drain();

        // The 50 s budget is checked before each send: 1 at 0 s and 2 at 30 s, then 60 s have passed.
        $this->assertSame([1, 2], $this->ran);
        $this->assertSame(1, $this->unlocks);
    }

    public function testItStopsAfterTheGlobalCapOfSendsPerRun(): void
    {
        $this->active(1);
        $this->due(DeliveryStatus::PENDING, 1, [1, 2, 3, 4]);

        $this->drainer(maxSends: 3)->drain();

        $this->assertSame([1, 2, 3], $this->ran);
        $this->assertSame(1, $this->unlocks);
    }

    public function testItSkipsARunWhoseLockIsHeldElsewhere(): void
    {
        $this->active(1);
        $this->due(DeliveryStatus::PENDING, 1, [1]);

        $this->drainer(lockWon: false)->drain();

        $this->assertSame([], $this->seeks);
        $this->assertSame(0, $this->unlocks);
    }

    public function testItReleasesTheLockWhenAScanFails(): void
    {
        $this->active(1);
        $this->failScan = true;

        $this->drainer()->drain();

        $this->assertSame(1, $this->unlocks);
    }

    public function testItClaimsAndSendsNothingWhileWebhooksAreDisabledInEnvPhp(): void
    {
        $this->active(1);
        $this->due(DeliveryStatus::PENDING, 1, [1]);

        $this->drainer(enabled: false)->drain();

        $this->assertSame([], $this->seeks);
        $this->assertSame([], $this->ran);
        $this->assertSame(0, $this->locks);
    }

    public function testItSkipsAnEndpointForTheRestOfTheRunOnceItsSlotsAreTaken(): void
    {
        $this->active(1);
        $this->active(2);
        $this->due(DeliveryStatus::PENDING, 1, [10, 11, 12, 13]);
        $this->due(DeliveryStatus::DELIVERING, 1, [14]);
        $this->due(DeliveryStatus::PENDING, 2, [20, 21]);
        $this->outcomes[1] = RunOutcome::SlotBusy;

        $this->drainer()->drain();

        $this->assertSame([10, 20, 21], $this->ran, 'endpoint 1 costs one attempt, then it is skipped');
        $this->assertSame([DeliveryStatus::PENDING => [1, 2], DeliveryStatus::DELIVERING => [2]], $this->seekedEndpoints());
    }

    public function testItLeavesRowsOfACircuitOpenEndpointUntouched(): void
    {
        $this->active(1, gmdate('Y-m-d H:i:s', WebhookFixtures::NOW + 600));
        $this->active(2, gmdate('Y-m-d H:i:s', WebhookFixtures::NOW - 1));
        $this->due(DeliveryStatus::PENDING, 1, [10]);
        $this->due(DeliveryStatus::PENDING, 2, [20]);

        $this->drainer()->drain();

        $this->assertSame([20], $this->ran, 'a circuit that already closed does not hold rows back');
        $this->assertNotContains(1, array_column($this->seeks, 1));
        $this->assertSame([], $this->cancels);
    }

    public function testItDrainsDueRowsFromEveryActiveEndpointInOneRun(): void
    {
        $this->active(1);
        $this->active(2);
        $this->due(DeliveryStatus::PENDING, 1, range(1, 1000));
        $this->due(DeliveryStatus::PENDING, 2, [5000]);

        $this->drainer(perEndpoint: 20, maxSends: 60)->drain();

        $position = array_search(5000, $this->ran, true);
        $this->assertNotFalse($position);
        $this->assertSame(20, $position, 'endpoint 2 is served in the first pass, after 20 rows of endpoint 1');
        $this->assertCount(60, $this->ran);
    }

    public function testItCancelsPendingRowsOfAnInactiveEndpointInBoundedBatches(): void
    {
        $this->inactive(3);
        $this->backlog[3] = 1200;

        $this->drainer()->drain();

        $this->assertSame([3, 3, 3], $this->cancels);
        $this->assertSame([500, 500, 500], $this->cancelLimits);
        $this->assertSame([], $this->ran);
        $this->assertSame(0, $this->backlog[3], 'the whole backlog is gone');
    }

    public function testItKeepsAndDrainsTestRowsOfAnInactiveEndpoint(): void
    {
        $this->inactive(3);
        $this->backlog[3] = 10;
        $this->due(DeliveryStatus::PENDING, 3, [31, 32]);

        $this->drainer()->drain();

        $this->assertSame([3], $this->cancels);
        $this->assertSame([31, 32], $this->ran);
        $this->assertSame(3, $this->seeks[0][1]);
    }

    public function testItDoesNotSeekTestRowsWhileAnInactiveEndpointStillHasABacklogToCancel(): void
    {
        $this->inactive(3);
        $this->backlog[3] = PHP_INT_MAX;
        $this->due(DeliveryStatus::PENDING, 3, [31]);
        $this->active(1);
        $this->due(DeliveryStatus::PENDING, 1, range(1, 6));

        $this->drainer(maxSends: 6)->drain();

        $this->assertGreaterThan(1, count($this->cancels), 'one batch per pass, every pass');
        $this->assertNotContains(3, array_column($this->seeks, 1));
        $this->assertNotContains(31, $this->ran);
    }

    public function testItSkipsTheRowsOfAPurgeRequestedEndpoint(): void
    {
        // The repository leaves purge-requested endpoints out of the routing states.
        $this->active(1);
        $this->due(DeliveryStatus::PENDING, 1, [1]);
        $this->due(DeliveryStatus::PENDING, 9, [90, 91]);
        $this->backlog[9] = 100;

        $this->drainer()->drain();

        $this->assertSame([1], $this->ran);
        $this->assertNotContains(9, array_column($this->seeks, 1));
        $this->assertNotContains(9, $this->cancels);
    }

    public function testItCountsSlotBusyOutcomesTowardTheRunCap(): void
    {
        foreach ([1, 2, 3, 4, 5] as $endpoint) {
            $this->active($endpoint);
            $this->due(DeliveryStatus::PENDING, $endpoint, [$endpoint * 10]);
            $this->outcomes[$endpoint] = RunOutcome::SlotBusy;
        }

        $this->drainer(maxSlotMisses: 3)->drain();

        $this->assertSame([10, 20, 30], $this->ran);
    }
}
