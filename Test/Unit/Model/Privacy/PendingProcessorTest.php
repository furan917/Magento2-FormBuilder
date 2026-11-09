<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Privacy;

use Furan\FormBuilder\Model\Privacy\Anonymiser;
use Furan\FormBuilder\Model\Privacy\PendingProcessor;
use Furan\FormBuilder\Model\Submission\BatchDeleter;
use Furan\FormBuilder\Model\Throttle\Counter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Notification\NotifierInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PendingProcessorTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private AdapterInterface&Stub $connection;
    private BatchDeleter&Stub $deleter;
    private Anonymiser&Stub $anonymiser;
    private NotifierInterface&Stub $notifier;
    private Counter&Stub $counter;
    private LoggerInterface&Stub $logger;
    private bool $lockFree = true;
    private bool $updateFails = false;
    /** @var float[] successive clock readings, the last one repeats */
    private array $clock = [self::NOW];
    /** @var list<array{string, array}> */
    private array $reads = [];
    /** @var list<array> ordered writes and transaction calls */
    private array $log = [];

    protected function setUp(): void
    {
        $this->deleter = $this->createStub(BatchDeleter::class);
        $this->anonymiser = $this->createStub(Anonymiser::class);
        $this->notifier = $this->createStub(NotifierInterface::class);
        $this->counter = $this->createStub(Counter::class);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    private function row(int $id, string $mode = 'anonymise', int $customerId = 9, int $attempts = 0, ?int $owner = null): array
    {
        return [
            'submission_id' => (string)$id, 'customer_id' => (string)$customerId, 'mode' => $mode,
            'attempts' => (string)$attempts, 'next_attempt_at' => '2027-01-15 08:00:00',
            'owner' => $owner === null ? null : (string)$owner,
        ];
    }

    /** @param list<list<array>> $pages pending rows served by successive reads */
    private function processor(array $pages): PendingProcessor
    {
        $this->connection = $this->createStub(AdapterInterface::class);
        $this->connection->method('fetchAll')->willReturnCallback(function (string $sql, array $bind) use (&$pages): array {
            $this->reads[] = [$sql, $bind];
            return array_shift($pages) ?? [];
        });
        foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
            $this->connection->method($method)->willReturnCallback(function () use ($method) {
                $this->log[] = [$method];
                return $this->connection;
            });
        }
        $this->connection->method('delete')->willReturnCallback(function (string $table, array $where): int {
            $this->log[] = ['delete', $table, $where];
            return 1;
        });
        $this->connection->method('update')->willReturnCallback(function (string $table, array $data, array $where): int {
            if ($this->updateFails) {
                throw new \RuntimeException('gone away');
            }
            $this->log[] = ['update', $table, $data, $where];
            return 1;
        });
        $this->deleter->method('deleteBatch')->willReturnCallback(function (array $ids): int {
            $this->log[] = ['deleteBatch', $ids];
            return count($ids);
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $locks = $this->createStub(LockManagerInterface::class);
        $locks->method('lock')->willReturnCallback(fn (string $name, int $timeout): bool => $name === PendingProcessor::LOCK_NAME && $timeout === 0 && $this->lockFree);
        $clock = fn (): float => count($this->clock) > 1 ? array_shift($this->clock) : $this->clock[0];

        return new PendingProcessor(
            $resource, $this->deleter, $this->anonymiser, $locks, $this->notifier, $this->counter, $this->logger, $clock
        );
    }

    public function testItReadsTheAccountsDueRowsByKeysetAndJoinsTheSubmissionOwner(): void
    {
        $first = array_map(fn (int $id): array => $this->row($id), range(1, 500));
        $this->processor([$first, [$this->row(501)]])->processCustomer(9);

        $this->assertCount(2, $this->reads);
        [$sql, $bind] = $this->reads[0];
        $this->assertStringContainsString('FROM furan_formbuilder_privacy_pending p JOIN furan_formbuilder_submission s ON s.submission_id = p.submission_id', $sql);
        $this->assertStringContainsString('p.customer_id = ? AND p.submission_id > ? AND p.next_attempt_at <= ?', $sql);
        $this->assertStringContainsString('ORDER BY p.submission_id LIMIT 500', $sql);
        $this->assertStringNotContainsString('OFFSET', $sql);
        $this->assertSame([9, 0, gmdate('Y-m-d H:i:s', self::NOW)], $bind);
        $this->assertSame([9, 500, gmdate('Y-m-d H:i:s', self::NOW)], $this->reads[1][1]);
    }

    public function testItErasesDeleteModeRowsThroughTheJournaledDeleterAndLetsTheirPendingRowsCascade(): void
    {
        $this->processor([[$this->row(4, 'delete'), $this->row(5, 'delete')]])->processCustomer(9);

        $this->assertSame([['deleteBatch', [4, 5]]], $this->log);
    }

    public function testItAnonymisesAndClearsThePendingRowsInOneTransaction(): void
    {
        $this->anonymiser->method('anonymise')->willReturnCallback(function (array $ids): void {
            $this->log[] = ['anonymise', $ids];
        });
        $this->processor([[$this->row(4), $this->row(5)]])->processCustomer(9);

        $this->assertSame([
            ['beginTransaction'],
            ['anonymise', [4, 5]],
            ['delete', 'furan_formbuilder_privacy_pending', ['submission_id IN (?)' => [4, 5]]],
            ['commit'],
        ], $this->log);
    }

    public function testItKeepsAFailedBatchPendingWithBackoffAndLogsNoSubmissionIds(): void
    {
        $this->anonymiser->method('anonymise')->willThrowException(new \RuntimeException('Lock wait timeout'));
        $logger = $this->logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->callback(static fn (string $m): bool => str_contains($m, 'customer 9') && str_contains($m, '2 submissions')
                && str_contains($m, 'Lock wait timeout') && !str_contains($m, '4') && !str_contains($m, '5,')),
            $this->callback(static fn (array $context): bool => !array_key_exists('submission_ids', $context))
        );

        $this->processor([[$this->row(4), $this->row(5)]])->processCustomer(9);

        $this->assertSame([
            ['beginTransaction'],
            ['rollBack'],
            ['update', 'furan_formbuilder_privacy_pending',
                ['attempts' => 1, 'next_attempt_at' => gmdate('Y-m-d H:i:s', self::NOW + 60)],
                ['submission_id IN (?)' => [4, 5]]],
        ], $this->log);
    }

    public static function backoff(): array
    {
        return [[0, 60], [1, 300], [2, 1800], [3, 7200], [4, 21600], [7, 21600]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backoff')]
    public function testItBacksOffOneMinuteFiveMinutesHalfAnHourTwoHoursThenEverySixHours(int $attempts, int $delay): void
    {
        $this->deleter->method('deleteBatch')->willThrowException(new \RuntimeException('deadlock'));
        $this->processor([[$this->row(4, 'delete', 9, $attempts)]])->processCustomer(9);

        $this->assertContains(['update', 'furan_formbuilder_privacy_pending',
            ['attempts' => $attempts + 1, 'next_attempt_at' => gmdate('Y-m-d H:i:s', self::NOW + $delay)],
            ['submission_id IN (?)' => [4]]], $this->log);
    }

    public function testItRaisesEachRowFromItsOwnAttemptCount(): void
    {
        $this->deleter->method('deleteBatch')->willThrowException(new \RuntimeException('deadlock'));
        $this->processor([[$this->row(4, 'delete', 9, 0), $this->row(5, 'delete', 9, 2)]])->processCustomer(9);

        $updates = array_values(array_filter($this->log, static fn (array $e): bool => $e[0] === 'update'));
        $this->assertSame([
            ['update', 'furan_formbuilder_privacy_pending', ['attempts' => 1, 'next_attempt_at' => gmdate('Y-m-d H:i:s', self::NOW + 60)], ['submission_id IN (?)' => [4]]],
            ['update', 'furan_formbuilder_privacy_pending', ['attempts' => 3, 'next_attempt_at' => gmdate('Y-m-d H:i:s', self::NOW + 1800)], ['submission_id IN (?)' => [5]]],
        ], $updates);
    }

    public function testItPostsOneAdminNoticeADayOnceRowsReachTenFailedAttempts(): void
    {
        $this->deleter->method('deleteBatch')->willThrowException(new \RuntimeException('deadlock'));
        $notifier = $this->notifier = $this->createMock(NotifierInterface::class);
        $counter = $this->counter = $this->createMock(Counter::class);
        $counter->expects($this->exactly(2))->method('hit')->with('ffb_privacy_pending_notice', 86400)->willReturnOnConsecutiveCalls(1, 2);
        $notifier->expects($this->once())->method('addMajor')->with(
            'Deleted customer data is waiting to be processed',
            $this->callback(static fn (string $text): bool => str_contains($text, '10'))
        );

        $this->processor([[$this->row(4, 'delete', 9, 9), $this->row(5, 'delete', 12, 12)]])->drain(60);
    }

    public function testItPostsNoNoticeBeforeTenFailedAttempts(): void
    {
        $this->deleter->method('deleteBatch')->willThrowException(new \RuntimeException('deadlock'));
        $notifier = $this->notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->never())->method('addMajor');

        $this->processor([[$this->row(4, 'delete', 9, 7)]])->processCustomer(9);
    }

    public function testItDropsPendingRowsWhoseSubmissionBelongsToAnAccountAgainWithoutTouchingThem(): void
    {
        $this->anonymiser->method('anonymise')->willReturnCallback(function (array $ids): void {
            $this->log[] = ['anonymise', $ids];
        });
        $this->processor([[$this->row(4, 'anonymise', 9, 0, 33), $this->row(5)]])->processCustomer(9);

        $this->assertSame(['delete', 'furan_formbuilder_privacy_pending', ['submission_id IN (?)' => [4]]], $this->log[0]);
        $this->assertContains(['anonymise', [5]], $this->log);
        $this->assertNotContains(['anonymise', [4, 5]], $this->log);
    }

    public function testItLeavesTheRowsToTheRunningSweepWhenTheLockIsHeld(): void
    {
        $this->lockFree = false;
        $this->processor([[$this->row(4)]])->processCustomer(9);
        $this->processor([[$this->row(4)]])->drain(60);

        $this->assertSame([], $this->reads);
    }

    public function testItSweepsDueRowsAcrossAccountsInDueOrderByKeysetAndGroupsThemByAccountAndMode(): void
    {
        $this->anonymiser->method('anonymise')->willReturnCallback(function (array $ids): void {
            $this->log[] = ['anonymise', $ids];
        });
        $page = array_merge(
            [$this->row(4, 'delete', 9), $this->row(6, 'anonymise', 12)],
            array_map(fn (int $id): array => $this->row($id, 'delete', 9), range(10, 507))
        );
        $this->processor([$page, []])->drain(60);

        $this->assertCount(2, $this->reads);
        [$sql, $bind] = $this->reads[0];
        $this->assertStringContainsString('p.next_attempt_at <= ?', $sql);
        $this->assertStringContainsString('ORDER BY p.next_attempt_at, p.submission_id LIMIT 500', $sql);
        $this->assertStringNotContainsString('OFFSET', $sql);
        $this->assertSame([gmdate('Y-m-d H:i:s', self::NOW)], $bind);
        $this->assertStringContainsString('(p.next_attempt_at > ? OR (p.next_attempt_at = ? AND p.submission_id > ?))', $this->reads[1][0]);
        $this->assertSame([gmdate('Y-m-d H:i:s', self::NOW), '2027-01-15 08:00:00', '2027-01-15 08:00:00', 507], $this->reads[1][1]);
        $this->assertContains(['deleteBatch', array_merge([4], range(10, 507))], $this->log);
        $this->assertContains(['anonymise', [6]], $this->log);
    }

    public function testItStopsTheSweepAtItsTimeBudget(): void
    {
        $this->clock = [self::NOW, self::NOW, self::NOW + 61];
        $page = array_map(fn (int $id): array => $this->row($id, 'delete'), range(1, 500));
        $this->processor([$page, $page, $page])->drain(60);

        $this->assertCount(1, $this->reads);
    }

    public function testItNeverThrowsWhenRecordingTheFailureFailsToo(): void
    {
        $this->deleter->method('deleteBatch')->willThrowException(new \RuntimeException('gone away'));
        $this->updateFails = true;

        $this->processor([[$this->row(4, 'delete')]])->processCustomer(9);
        $this->addToAssertionCount(1);
    }
}
