<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Form;

use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Model\Form\ScheduleCachePurger;
use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\CacheContextFactory;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ScheduleCachePurgerTest extends TestCase
{
    private const NOW = 1800000000;
    private const FLAG = 'furan_formbuilder_schedule_last_run';

    /** @var array<int, array{0: string, 1: array}> */
    private array $selects = [];
    /** @var array<int, array{0: string, 1: array}> */
    private array $dispatched = [];
    /** @var array<int, array<int, int>> */
    private array $registered = [];
    /** @var array<int, array{0: string, 1: mixed}> */
    private array $savedFlags = [];
    private FlagManager&Stub $flags;
    private LockManagerInterface&Stub $locks;

    protected function setUp(): void
    {
        $this->selects = [];
        $this->dispatched = [];
        $this->registered = [];
        $this->savedFlags = [];
        $this->flags = $this->createStub(FlagManager::class);
        $this->flags->method('saveFlag')->willReturnCallback(function (string $code, mixed $value): bool {
            $this->savedFlags[] = [$code, $value];
            return true;
        });
        $this->locks = $this->createStub(LockManagerInterface::class);
        $this->locks->method('lock')->willReturn(true);
    }

    private static function at(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /**
     * @param array<int, array{form_id: string, at: string}> $opens
     * @param array<int, array{form_id: string, at: string}> $closes
     */
    private function purger(array $opens, array $closes, ?\Throwable $dispatchFailure = null): ScheduleCachePurger
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchAll')->willReturnCallback(
            function (string $sql, array $bind) use ($opens, $closes): array {
                $this->selects[] = [$sql, $bind];
                $rows = str_contains($sql, 'open_from') ? $opens : $closes;
                $rows = array_values(array_filter(
                    $rows,
                    static fn (array $r): bool => ($r['at'] > $bind[0] || ($r['at'] === $bind[1] && (int)$r['form_id'] > $bind[2]))
                        && $r['at'] <= $bind[3]
                ));
                usort($rows, static fn (array $a, array $b): int => [$a['at'], (int)$a['form_id']] <=> [$b['at'], (int)$b['form_id']]);
                return array_slice($rows, 0, ScheduleCachePurger::BATCH_SIZE);
            }
        );
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $context = $this->createStub(CacheContext::class);
        $context->method('registerEntities')->willReturnCallback(
            function (string $tag, array $ids) use ($context): CacheContext {
                $this->registered[] = [$tag, $ids];
                return $context;
            }
        );
        $factory = $this->createStub(CacheContextFactory::class);
        $factory->method('create')->willReturn($context);
        $events = $this->createStub(ManagerInterface::class);
        $events->method('dispatch')->willReturnCallback(function (string $name, array $data) use ($context, $dispatchFailure): void {
            if ($dispatchFailure) {
                throw $dispatchFailure;
            }
            $this->dispatched[] = [$name, $data];
            $this->assertSame($context, $data['object']);
        });

        return new ScheduleCachePurger(
            $resource,
            $this->flags,
            $factory,
            $events,
            $this->locks,
            static fn (): int => self::NOW
        );
    }

    /** @return array<string, array{t: int, id: int}> */
    private static function cursors(int $ts, int $id = PHP_INT_MAX): array
    {
        return ['open_from' => ['t' => $ts, 'id' => $id], 'close_at' => ['t' => $ts, 'id' => $id]];
    }

    private function row(int $id, int $ts): array
    {
        return ['form_id' => (string)$id, 'at' => self::at($ts)];
    }

    public function testItCleansTheCacheTagsOfAFormWhoseOpenTimeJustPassed(): void
    {
        $this->flags->method('getFlagData')->willReturn(self::cursors(self::NOW - 60));
        $this->purger([$this->row(7, self::NOW - 30)], [])->run();

        $this->assertSame([[Form::CACHE_TAG, [7]]], $this->registered);
    }

    public function testItCleansTheCacheTagsOfAFormWhoseCloseTimeJustPassed(): void
    {
        $this->flags->method('getFlagData')->willReturn(self::cursors(self::NOW - 60));
        $this->purger([], [$this->row(9, self::NOW - 10)])->run();

        $this->assertSame([[Form::CACHE_TAG, [9]]], $this->registered);
    }

    public function testItIgnoresFormsWhoseTimesAreOutsideTheWindow(): void
    {
        $this->flags->method('getFlagData')->willReturn(self::cursors(self::NOW - 60));
        $this->purger(
            [$this->row(1, self::NOW - 61), $this->row(2, self::NOW + 1), $this->row(3, self::NOW - 60)],
            [$this->row(4, self::NOW - 3600)]
        )->run();

        $this->assertSame([], $this->registered);
        $this->assertSame([], $this->dispatched);
    }

    public function testItUsesTwoIndexedRangeQueriesOnActiveForms(): void
    {
        $this->flags->method('getFlagData')->willReturn(self::cursors(self::NOW - 60));
        $this->purger([], [])->run();

        $this->assertCount(2, $this->selects);
        foreach ($this->selects as [$sql, $bind]) {
            $this->assertStringContainsString('is_active = 1', $sql);
            $this->assertStringContainsString('LIMIT', $sql);
            $this->assertStringContainsString('ORDER BY', $sql);
            $this->assertSame([self::at(self::NOW - 60), self::at(self::NOW - 60), PHP_INT_MAX, self::at(self::NOW)], $bind);
        }
    }

    public function testItRemembersTheLastRunTime(): void
    {
        $this->flags->method('getFlagData')->willReturn(self::cursors(self::NOW - 60));
        $this->purger([$this->row(7, self::NOW - 30)], [])->run();

        $this->assertSame([[self::FLAG, self::cursors(self::NOW)]], $this->savedFlags);
    }

    public function testItDoesNotAdvanceTheLastRunWhenTheCleanFails(): void
    {
        $this->flags->method('getFlagData')->willReturn(self::cursors(self::NOW - 60));
        $purger = $this->purger([$this->row(7, self::NOW - 30)], [], new \RuntimeException('varnish down'));

        try {
            $purger->run();
            $this->fail('Expected the failure to surface');
        } catch (\RuntimeException) {
            $this->assertSame([], $this->savedFlags);
        }
    }

    public function testItLimitsTheWindowOnTheFirstRun(): void
    {
        $this->flags->method('getFlagData')->willReturn(null);
        $this->purger(
            [$this->row(1, self::NOW - 601), $this->row(2, self::NOW - 599)],
            []
        )->run();

        $this->assertSame([[Form::CACHE_TAG, [2]]], $this->registered);
        $this->assertSame(self::at(self::NOW - ScheduleCachePurger::FIRST_RUN_WINDOW), $this->selects[0][1][0]);
    }

    public function testItCleansTheTagsThroughTheCleanCacheByTagsEventSoVarnishIsPurged(): void
    {
        $this->flags->method('getFlagData')->willReturn(self::cursors(self::NOW - 60));
        $this->purger(
            [$this->row(7, self::NOW - 30), $this->row(8, self::NOW - 20)],
            [$this->row(7, self::NOW - 10), $this->row(9, self::NOW - 5)]
        )->run();

        $this->assertCount(1, $this->dispatched);
        $this->assertSame('clean_cache_by_tags', $this->dispatched[0][0]);
        $this->assertSame([[Form::CACHE_TAG, [7, 8, 9]]], $this->registered);
    }

    public function testItSkipsTheRunWhenAnotherRunHoldsTheLock(): void
    {
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->expects($this->once())->method('lock')
            ->with(ScheduleCachePurger::LOCK_NAME, 0)->willReturn(false);
        $locks->expects($this->never())->method('unlock');
        $this->locks = $locks;
        $purger = $this->purger([$this->row(7, self::NOW - 30)], []);

        $purger->run();

        $this->assertSame([], $this->selects);
        $this->assertSame([], $this->savedFlags);
    }

    public function testItContinuesFromTheLastRowWhenABatchIsFull(): void
    {
        $this->flags->method('getFlagData')->willReturn(self::cursors(self::NOW - 100));
        $opens = [];
        for ($i = 0; $i < ScheduleCachePurger::BATCH_SIZE; $i++) {
            $opens[] = $this->row($i + 1, self::NOW - 90 + intdiv($i, 100));
        }
        $opens[] = $this->row(5000, self::NOW - 10);
        $this->purger($opens, [])->run();

        $ids = array_merge(...array_column($this->registered, 1));
        $this->assertContains(5000, $ids);
        $this->assertCount(ScheduleCachePurger::BATCH_SIZE + 1, array_unique($ids));
        $this->assertSame(self::cursors(self::NOW), end($this->savedFlags)[1]);
    }

    public function testItPurgesEveryFormWhoseTimePassedInTheSameSecondBeyondOneBatch(): void
    {
        $this->flags->method('getFlagData')->willReturn(self::cursors(self::NOW - 100));
        $opens = [];
        for ($i = 1; $i <= ScheduleCachePurger::BATCH_SIZE + 1; $i++) {
            $opens[] = $this->row($i, self::NOW - 50);
        }
        $this->purger($opens, [])->run();

        $ids = array_merge(...array_column($this->registered, 1));
        $this->assertCount(ScheduleCachePurger::BATCH_SIZE + 1, array_unique($ids));
        $this->assertSame(self::cursors(self::NOW), end($this->savedFlags)[1]);
    }

    public function testItRemembersTheLastFormIdWithinASecondBetweenRuns(): void
    {
        $this->flags->method('getFlagData')->willReturn(self::cursors(self::NOW - 100));
        $opens = [];
        for ($i = 1; $i <= ScheduleCachePurger::BATCH_SIZE + 1; $i++) {
            $opens[] = $this->row($i, self::NOW - 50);
        }
        $this->purger($opens, [])->run(0);

        $this->assertSame(
            ['t' => self::NOW - 50, 'id' => ScheduleCachePurger::BATCH_SIZE],
            $this->savedFlags[0][1]['open_from']
        );
        $this->assertSame([[Form::CACHE_TAG, range(1, ScheduleCachePurger::BATCH_SIZE)]], $this->registered);
    }
}
