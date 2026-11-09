<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Cron;

use Furan\FormBuilder\Model\Cron\StatsRebuildQueue;
use Furan\FormBuilder\Model\Stats\RebuildQueue;
use Furan\FormBuilder\Model\Stats\Rebuilder;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StatsRebuildQueueTest extends TestCase
{
    private RebuildQueue&Stub $queue;
    private Rebuilder&Stub $rebuilder;
    private LockManagerInterface&Stub $locks;
    private LoggerInterface&Stub $logger;
    private array $calls = [];

    protected function setUp(): void
    {
        $this->queue = $this->createStub(RebuildQueue::class);
        $this->rebuilder = $this->createStub(Rebuilder::class);
        $this->locks = $this->createStub(LockManagerInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    private function job(?callable $clock = null): StatsRebuildQueue
    {
        return new StatsRebuildQueue($this->queue, $this->rebuilder, $this->locks, $this->logger, $clock);
    }

    public function testItRebuildsQueuedFormsFromACronJobInItsOwnCronGroup(): void
    {
        $rebuilder = $this->rebuilder = $this->createMock(Rebuilder::class);
        $this->queue->method('queued')->willReturn([4, 9]);
        $rebuilder->expects($this->exactly(2))->method('rebuild')
            ->willReturnCallback(function (int $id, bool $reset = false): void {
                $this->calls[] = [$id, $reset];
            });

        $this->job()->execute();

        $this->assertSame([[4, false], [9, false]], $this->calls);

        $root = __DIR__ . '/../../../../etc/';
        $groups = simplexml_load_file($root . 'cron_groups.xml');
        $this->assertSame('1', (string)$groups->xpath('//group[@id="furan_formbuilder_stats"]/use_separate_process')[0]);
        $crontab = simplexml_load_file($root . 'crontab.xml');
        $job = $crontab->xpath('//group[@id="furan_formbuilder_stats"]/job[@name="furan_formbuilder_stats_rebuild_queue"]')[0];
        $this->assertSame(StatsRebuildQueue::class, (string)$job['instance']);
        $this->assertSame('30 2 * * *', (string)$job->schedule);
    }

    public function testItClearsTheRequestBeforeRebuildingSoAFailingRebuildIsNotRetriedEveryNight(): void
    {
        $logger = $this->logger = $this->createMock(LoggerInterface::class);
        $this->queue->method('queued')->willReturn([4, 9]);
        $this->queue->method('release')->willReturnCallback(function (int $id): void {
            $this->calls[] = 'release ' . $id;
        });
        $this->rebuilder->method('rebuild')->willReturnCallback(function (int $id): void {
            $this->calls[] = 'rebuild ' . $id;
            if ($id === 4) {
                throw new \RuntimeException('boom');
            }
        });
        $logger->expects($this->once())->method('error');

        $this->job()->execute();

        $this->assertSame(['release 4', 'rebuild 4', 'release 9', 'rebuild 9'], $this->calls);
    }

    public function testItKeepsAFormQueuedWhenItsRebuildLockIsHeld(): void
    {
        $rebuilder = $this->rebuilder = $this->createMock(Rebuilder::class);
        $queue = $this->queue = $this->createMock(RebuildQueue::class);
        $this->queue->method('queued')->willReturn([4, 9]);
        $this->locks->method('isLocked')->willReturnCallback(
            static fn (string $name) => $name === 'furanform_stats_rebuild_4'
        );
        $queue->expects($this->once())->method('release')->with(9);
        $rebuilder->expects($this->once())->method('rebuild')->with(9);

        $this->job()->execute();
    }

    public function testItStopsStartingNewFormsAfterThreeHours(): void
    {
        $rebuilder = $this->rebuilder = $this->createMock(Rebuilder::class);
        $times = [0.0, 100.0, 3 * 3600 + 1.0];
        $this->queue->method('queued')->willReturn([1, 2, 3]);
        $rebuilder->expects($this->once())->method('rebuild')->with(1);

        $this->job(static function () use (&$times): float {
            return count($times) > 1 ? array_shift($times) : $times[0];
        })->execute();
    }
}
